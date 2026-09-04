<?php

namespace App\Modules\Kitchen\Services;

use App\Modules\Kitchen\Models\Item;
use App\Modules\Platform\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Pengelola stok bahan/barang dapur — pola yang sama dengan
 * Inventory\Services\StockLedger (UPDATE bersyarat untuk pengurangan,
 * setiap perubahan saldo tercatat di stock_movements), tanpa
 * batch/FEFO/kedaluwarsa (Wave 1 non-perishable-aware — bahan basah
 * dengan kedaluwarsa harian belum digarap, lihat catatan modul).
 * quantity_on_hand di items adalah cache; kebenarannya bisa diuji ulang
 * lewat reconcile().
 */
class StockLedger
{
    public function receive(
        int $itemId,
        float $quantity,
        string $source,
        ?string $referenceType = null,
        ?int $referenceId = null,
        ?User $actor = null,
        ?string $note = null,
    ): Item {
        if ($quantity <= 0) {
            throw new KitchenException('Jumlah barang masuk harus lebih dari nol.');
        }

        return DB::transaction(function () use ($itemId, $quantity, $source, $referenceType, $referenceId, $actor, $note): Item {
            $row = DB::selectOne(
                'UPDATE kitchen.items
                    SET quantity_on_hand = quantity_on_hand + ?, updated_at = now()
                  WHERE id = ?
              RETURNING quantity_on_hand',
                [$quantity, $itemId]
            );

            $this->log($itemId, 'masuk', $source, $quantity, (float) $row->quantity_on_hand, $referenceType, $referenceId, $note, $actor);

            return Item::query()->findOrFail($itemId);
        });
    }

    /** @throws KitchenException bila stok tidak cukup */
    public function issue(
        int $itemId,
        float $quantity,
        string $source,
        ?string $referenceType = null,
        ?int $referenceId = null,
        ?User $actor = null,
        ?string $note = null,
    ): Item {
        if ($quantity <= 0) {
            throw new KitchenException('Jumlah yang dikeluarkan harus lebih dari nol.');
        }

        return DB::transaction(function () use ($itemId, $quantity, $source, $referenceType, $referenceId, $actor, $note): Item {
            $row = DB::selectOne(
                'UPDATE kitchen.items
                    SET quantity_on_hand = quantity_on_hand - ?, updated_at = now()
                  WHERE id = ? AND quantity_on_hand >= ?
              RETURNING quantity_on_hand',
                [$quantity, $itemId, $quantity]
            );

            if ($row === null) {
                $tersedia = (float) Item::query()->findOrFail($itemId)->quantity_on_hand;

                throw new KitchenException(sprintf('Stok tidak cukup. Tersedia %s, diminta %s.', $tersedia, $quantity));
            }

            $this->log($itemId, 'keluar', $source, -$quantity, (float) $row->quantity_on_hand, $referenceType, $referenceId, $note, $actor);

            return Item::query()->findOrFail($itemId);
        });
    }

    /** Stok opname: menetapkan saldo hasil hitung fisik, mencatat selisihnya sebagai satu baris ledger. */
    public function opname(int $itemId, float $countedQuantity, ?User $actor = null, ?string $note = null): Item
    {
        if ($countedQuantity < 0) {
            throw new KitchenException('Hasil hitung fisik tidak boleh negatif.');
        }

        return DB::transaction(function () use ($itemId, $countedQuantity, $actor, $note): Item {
            $item = Item::query()->findOrFail($itemId);
            $selisih = $countedQuantity - (float) $item->quantity_on_hand;

            $item->update(['quantity_on_hand' => $countedQuantity]);

            if ($selisih !== 0.0) {
                $this->log($itemId, 'opname', 'opname', $selisih, $countedQuantity, null, null, $note, $actor);
            }

            return $item->refresh();
        });
    }

    /** Menguji saldo item terhadap buku besarnya. */
    public function reconcile(int $itemId): array
    {
        $saldo = (float) Item::query()->whereKey($itemId)->value('quantity_on_hand');
        $bukuBesar = (float) DB::table('kitchen.stock_movements')->where('item_id', $itemId)->sum('quantity');

        return [
            'saldo_tercatat' => $saldo,
            'saldo_buku_besar' => $bukuBesar,
            'selisih' => round($saldo - $bukuBesar, 2),
            'cocok' => abs($saldo - $bukuBesar) < 0.001,
        ];
    }

    private function log(
        int $itemId,
        string $kind,
        string $source,
        float $quantity,
        float $balanceAfter,
        ?string $referenceType,
        ?int $referenceId,
        ?string $note,
        ?User $actor,
    ): void {
        DB::table('kitchen.stock_movements')->insert([
            'item_id' => $itemId,
            'kind' => $kind,
            'source' => $source,
            'quantity' => $quantity,
            'balance_after' => $balanceAfter,
            'reference_type' => $referenceType,
            'reference_id' => $referenceId,
            'note' => $note,
            'created_by' => $actor?->id,
            'moved_at' => now(),
        ]);
    }
}
