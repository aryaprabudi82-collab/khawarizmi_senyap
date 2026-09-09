<?php

namespace App\Modules\Retail\Services;

use App\Modules\Retail\Models\Product;
use App\Modules\Retail\Models\StockMovement;
use Illuminate\Support\Facades\DB;

/**
 * Buku besar stok toko.
 *
 * Implementasi ketiga dari mekanisme yang sama (pharmacy, inventory, dan
 * kini retail). Duplikasinya disengaja: menyatukan ketiganya akan
 * melahirkan kesalahan yang jauh lebih mahal — permintaan bangsal
 * menarik stok barang dagangan koperasi, atau obat terjual di kasir
 * toko. Kalau muncul instansi keempat, menyari mekanisme ini jadi satu
 * komponen bersama jadi pilihan yang lebih murah daripada menyalinnya
 * sekali lagi.
 */
class RetailStockLedger
{
    /**
     * Mencatat pergerakan stok.
     *
     * ARAH DITETAPKAN DARI JENISNYA, tidak diterima dari pemanggil.
     * Pemanggil yang boleh menentukan arah bisa menambah stok lewat
     * penjualan — aturan yang sama seperti arah kas pada finance dan arah
     * cairan pada clinical.
     */
    public function record(
        Product $product,
        string $kind,
        int $quantity,
        ?float $unitCost = null,
        ?string $reference = null,
        ?int $recordedBy = null,
        ?string $note = null
    ): StockMovement {
        if (! isset(StockMovement::ARAH[$kind])) {
            throw new RetailException(
                'Jenis pergerakan "'.$kind.'" tidak punya arah yang ditetapkan; '.
                'koreksi hanya boleh lahir dari penyelesaian stok opname.'
            );
        }

        if ($quantity <= 0) {
            throw new RetailException(
                'Jumlah pergerakan harus lebih dari nol — arahnya ditentukan jenisnya, bukan tandanya.'
            );
        }

        $arah = StockMovement::ARAH[$kind];

        if ($arah < 0) {
            $this->assertStokCukup($product, $quantity);
        }

        return StockMovement::query()->create([
            'product_id' => $product->id,
            'kind' => $kind,
            'quantity' => $arah * $quantity,
            'unit_cost' => $unitCost,
            'reference' => $reference,
            'recorded_by' => $recordedBy,
            'note' => $note,
            'occurred_at' => now(),
        ]);
    }

    /**
     * Koreksi hasil stok opname — satu-satunya pergerakan yang tandanya
     * bebas, karena arahnya memang tergantung hasil hitung fisik.
     */
    public function correct(
        Product $product,
        int $signedQuantity,
        float $unitCost,
        string $reference,
        ?int $recordedBy = null,
        ?string $note = null
    ): ?StockMovement {
        if ($signedQuantity === 0) {
            // Selisih nol tidak menghasilkan baris: riwayat yang berisi
            // pergerakan tanpa akibat membuat penelusuran selisih jauh
            // lebih lama.
            return null;
        }

        return StockMovement::query()->create([
            'product_id' => $product->id,
            'kind' => StockMovement::KOREKSI,
            'quantity' => $signedQuantity,
            'unit_cost' => $unitCost,
            'reference' => $reference,
            'recorded_by' => $recordedBy,
            'note' => $note,
            'occurred_at' => now(),
        ]);
    }

    /** Stok saat ini — DIHITUNG, tidak pernah dibaca dari kolom. */
    public function stock(Product $product): int
    {
        return (int) StockMovement::query()->where('product_id', $product->id)->sum('quantity');
    }

    /**
     * Stok seluruh produk sekaligus, untuk layar daftar.
     *
     * @return array<int, int>
     */
    public function stockMap(): array
    {
        return DB::table('retail.stock_movements')
            ->selectRaw('product_id, COALESCE(SUM(quantity), 0) AS saldo')
            ->groupBy('product_id')
            ->pluck('saldo', 'product_id')
            ->map(fn ($n) => (int) $n)
            ->all();
    }

    private function assertStokCukup(Product $product, int $quantity): void
    {
        $tersedia = $this->stock($product);

        if ($tersedia < $quantity) {
            throw new RetailException(
                'Stok '.$product->name.' tinggal '.$tersedia.', tidak cukup untuk '.$quantity.'. '.
                'Stok minus berarti barang terjual lebih banyak daripada yang pernah masuk — '.
                'yang tersembunyi di situ bukan kesalahan hitung, melainkan penerimaan yang tidak dicatat.'
            );
        }
    }
}
