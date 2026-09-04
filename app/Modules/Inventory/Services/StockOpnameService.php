<?php

namespace App\Modules\Inventory\Services;

use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\StockOpname;
use App\Modules\Platform\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * stok_opname_logistik (Stok Opname Non Medis) — sesi hitung fisik
 * multi-barang, beda dari MasterDataController::opname() yang ad-hoc
 * 1 barang (tetap ada, di bawah ipsrs_barang, untuk koreksi cepat di
 * luar sesi resmi). Selisih disesuaikan lewat StockLedger::opname()
 * per baris saat sesi diselesaikan — satu-satunya jalan penyesuaian,
 * baik dari sini maupun dari opname() ad-hoc.
 */
class StockOpnameService
{
    public function __construct(
        private readonly NumberAllocator $numbers,
        private readonly StockLedger $ledger,
    ) {}

    /** Membuka sesi opname untuk seluruh barang aktif, snapshot saldo sistem saat ini. */
    public function start(?string $notes, int $createdBy): StockOpname
    {
        return DB::transaction(function () use ($notes, $createdBy): StockOpname {
            $opname = StockOpname::query()->create([
                'opname_number' => $this->numbers->allocate('OPN'),
                'status' => StockOpname::STATUS_DRAF,
                'notes' => $notes,
                'created_by' => $createdBy,
            ]);

            $barang = Item::query()->where('is_active', true)->get();

            foreach ($barang as $item) {
                $opname->items()->create([
                    'item_id' => $item->id,
                    'system_quantity' => $item->quantity_on_hand,
                ]);
            }

            return $opname->fresh('items');
        });
    }

    public function recordCount(StockOpname $opname, int $baris, float $counted, ?string $note = null): void
    {
        if ($opname->status !== StockOpname::STATUS_DRAF) {
            throw new InventoryException('Opname ini sudah diselesaikan.');
        }

        $opname->items()->whereKey($baris)->update(['counted_quantity' => $counted, 'note' => $note]);
    }

    /** Menutup opname: tiap baris yang sudah dihitung dan berbeda dari saldo sistem disesuaikan lewat StockLedger::opname(). Baris yang belum dihitung dianggap sesuai (dilewati). */
    public function complete(StockOpname $opname, User $actor): StockOpname
    {
        if ($opname->status !== StockOpname::STATUS_DRAF) {
            throw new InventoryException('Opname ini sudah diselesaikan.');
        }

        return DB::transaction(function () use ($opname, $actor): StockOpname {
            // load() paksa, bukan cache lama — recordCount() mengubah
            // counted_quantity lewat query terpisah setelah $opname
            // mungkin sudah memuat relasi items-nya (lihat catatan yang
            // sama di PurchaseOrderService::refreshReceivingStatus()).
            $opname->load('items');

            foreach ($opname->items as $baris) {
                $selisih = $baris->difference();

                if ($selisih === null || abs($selisih) < 0.0001) {
                    continue;
                }

                $this->ledger->opname(
                    $baris->item_id,
                    (float) $baris->counted_quantity,
                    $actor,
                    "Opname {$opname->opname_number}" . ($baris->note ? " — {$baris->note}" : ''),
                );
            }

            $opname->update(['status' => StockOpname::STATUS_SELESAI, 'completed_at' => now()]);

            return $opname->refresh();
        });
    }
}
