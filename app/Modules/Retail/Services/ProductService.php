<?php

namespace App\Modules\Retail\Services;

use App\Modules\Retail\Models\PriceTier;
use App\Modules\Retail\Models\PricingPolicy;
use App\Modules\Retail\Models\Product;
use App\Modules\Retail\Models\ProductPrice;
use App\Modules\Retail\Models\StockOpname;
use App\Modules\Retail\Models\StockOpnameItem;
use Illuminate\Support\Facades\DB;

/**
 * Produk, harga, dan stok opname toko (domain S item A).
 */
class ProductService
{
    public function __construct(
        private readonly RetailNumberAllocator $numbers,
        private readonly RetailStockLedger $ledger,
    ) {}

    /**
     * Menetapkan harga jual satu produk pada satu tingkat.
     *
     * Harga BARANG yang berubah tidak mengubah penjualan yang sudah
     * terjadi: harga jual dibekukan per baris penjualan saat transaksi.
     */
    public function setPrice(Product $product, PriceTier $tier, float $price): ProductPrice
    {
        if ($price < 0) {
            throw new RetailException('Harga jual negatif bukan diskon, ia salah ketik.');
        }

        return ProductPrice::query()->updateOrCreate(
            ['product_id' => $product->id, 'price_tier_id' => $tier->id],
            ['price' => $price]
        );
    }

    /**
     * Harga usulan dari patokan marjin yang BERLAKU SAAT INI.
     *
     * Sekadar usulan: harga akhir tetap ditetapkan manusia lewat
     * setPrice(). Menerapkannya otomatis akan mengubah harga seluruh
     * barang begitu patokannya diubah, termasuk barang yang harganya
     * memang sengaja ditetapkan di luar patokan.
     */
    public function suggestedPrice(Product $product, PriceTier $tier): ?float
    {
        $patokan = PricingPolicy::query()
            ->where('price_tier_id', $tier->id)
            ->where('is_active', true)
            ->first();

        if ($patokan === null) {
            return null;
        }

        return round((float) $product->base_cost * (1 + (float) $patokan->markup_percent / 100), 2);
    }

    /** Patokan marjin baru; yang lama dinonaktifkan, tidak ditimpa. */
    public function setPricingPolicy(PriceTier $tier, float $markupPercent, ?int $createdBy = null): PricingPolicy
    {
        return DB::transaction(function () use ($tier, $markupPercent, $createdBy) {
            PricingPolicy::query()
                ->where('price_tier_id', $tier->id)
                ->where('is_active', true)
                ->update(['is_active' => false]);

            return PricingPolicy::query()->create([
                'price_tier_id' => $tier->id,
                'markup_percent' => $markupPercent,
                'effective_from' => now()->toDateString(),
                'is_active' => true,
                'created_by' => $createdBy,
            ]);
        });
    }

    // ------------------------------------------------------ stok opname

    /**
     * Membuka sesi opname.
     *
     * Stok menurut buku besar DIBEKUKAN ke tiap baris saat sesi dibuka —
     * bukan dibaca ulang saat ditutup. Kalau dibaca ulang, penjualan yang
     * terjadi selama penghitungan fisik berlangsung akan tampak sebagai
     * selisih hitung, dan petugas akan mengejar kehilangan yang tidak
     * pernah ada.
     */
    public function openOpname(array $data, ?int $countedBy = null, ?string $countedByName = null): StockOpname
    {
        return DB::transaction(function () use ($data, $countedBy, $countedByName) {
            $opname = StockOpname::query()->create($data + [
                'opname_number' => $this->numbers->allocate('OPN'),
                'counted_on' => now()->toDateString(),
                'status' => StockOpname::STATUS_BERJALAN,
                'counted_by' => $countedBy,
                'counted_by_name' => $countedByName,
            ]);

            $saldo = $this->ledger->stockMap();

            foreach (Product::query()->where('is_active', true)->get() as $produk) {
                $opname->items()->create([
                    'product_id' => $produk->id,
                    'system_quantity' => $saldo[$produk->id] ?? 0,
                    'counted_quantity' => null,
                    'unit_cost' => $produk->base_cost,
                ]);
            }

            return $opname->load('items');
        });
    }

    public function recordCount(StockOpnameItem $item, ?int $counted, ?string $note = null): StockOpnameItem
    {
        if ($item->opname->status !== StockOpname::STATUS_BERJALAN) {
            throw new RetailException('Sesi opname ini sudah selesai atau dibatalkan.');
        }

        if ($counted !== null && $counted < 0) {
            throw new RetailException('Hasil hitung fisik tidak bisa negatif.');
        }

        $item->update(['counted_quantity' => $counted, 'note' => $note]);

        return $item->refresh();
    }

    /**
     * Menutup sesi opname dan menerbitkan koreksinya.
     *
     * DITAHAN selama masih ada baris yang belum dihitung fisik: menutup
     * opname dengan baris kosong berarti stok barang itu dikoreksi ke
     * angka yang tidak pernah dihitung siapa pun — dan sesudahnya buku
     * besar akan tampak sudah dicocokkan.
     *
     * Selisihnya masuk buku besar sebagai koreksi, TIDAK menimpa saldo:
     * selisih yang ditimpakan menghapus sebabnya bersama angkanya.
     */
    public function completeOpname(StockOpname $opname, ?int $recordedBy = null): StockOpname
    {
        if ($opname->status !== StockOpname::STATUS_BERJALAN) {
            throw new RetailException('Sesi opname ini sudah selesai atau dibatalkan.');
        }

        $opname->load('items.product');
        $belum = $opname->belumDihitung();

        if ($belum !== []) {
            throw new RetailException(
                'Belum bisa ditutup — '.count($belum).' barang belum dihitung fisik. '.
                'Menutupnya berarti mengoreksi stok ke angka yang tidak pernah dihitung siapa pun.'
            );
        }

        return DB::transaction(function () use ($opname, $recordedBy) {
            foreach ($opname->items as $baris) {
                $this->ledger->correct(
                    $baris->product,
                    (int) $baris->selisih(),
                    (float) $baris->unit_cost,
                    $opname->opname_number,
                    $recordedBy,
                    $baris->note
                );
            }

            $opname->update([
                'status' => StockOpname::STATUS_SELESAI,
                'completed_at' => now(),
            ]);

            return $opname->refresh();
        });
    }

    public function cancelOpname(StockOpname $opname): StockOpname
    {
        if ($opname->status !== StockOpname::STATUS_BERJALAN) {
            throw new RetailException('Sesi opname ini sudah selesai atau dibatalkan.');
        }

        $opname->update(['status' => StockOpname::STATUS_DIBATALKAN]);

        return $opname->refresh();
    }
}
