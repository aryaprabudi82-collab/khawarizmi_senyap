<?php

namespace App\Modules\Pharmacy\Services;

use App\Modules\Pharmacy\Models\StockBatch;
use App\Modules\Platform\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Pengelola stok per batch.
 *
 * Aturan yang dijaga di sini:
 *
 *  - Pengurangan stok terjadi di dalam satu pernyataan UPDATE bersyarat.
 *    Dua petugas yang menyerahkan obat terakhir pada saat bersamaan tidak
 *    bisa keduanya berhasil: yang kalah balapan mendapat nol baris terpengaruh
 *    dan langsung ditolak. Pola SELECT stok lalu UPDATE punya celah di
 *    antaranya, dan pada jam ramai celah itu berarti stok minus.
 *
 *  - Setiap perubahan saldo meninggalkan baris di buku besar. Saldo di
 *    stock_batches boleh dianggap cache; kebenarannya selalu bisa diuji
 *    ulang terhadap buku besar — dan untuk narkotika, buku besar inilah
 *    dasar pelaporannya.
 *
 *  - Pengambilan memakai kaidah FEFO: batch yang paling dekat kedaluwarsa
 *    keluar lebih dulu, bukan yang paling dulu masuk.
 */
class StockLedger
{
    /**
     * Menambah stok masuk. Batch dibuat bila belum ada.
     */
    public function receive(
        int $drugId,
        int $locationId,
        string $batchNumber,
        float $quantity,
        ?string $expiryDate = null,
        float $costPrice = 0,
        ?User $actor = null,
        ?string $note = null,
    ): StockBatch {
        if ($quantity <= 0) {
            throw new PharmacyException('Jumlah barang masuk harus lebih dari nol.');
        }

        return DB::transaction(function () use (
            $drugId, $locationId, $batchNumber, $quantity, $expiryDate, $costPrice, $actor, $note
        ): StockBatch {
            $batch = StockBatch::query()->firstOrCreate(
                ['drug_id' => $drugId, 'location_id' => $locationId, 'batch_number' => $batchNumber],
                ['expiry_date' => $expiryDate, 'quantity_on_hand' => 0, 'cost_price' => $costPrice],
            );

            $row = DB::selectOne(
                'UPDATE pharmacy.stock_batches
                    SET quantity_on_hand = quantity_on_hand + ?, updated_at = now()
                  WHERE id = ?
              RETURNING quantity_on_hand',
                [$quantity, $batch->id]
            );

            $this->log($batch, 'masuk', $quantity, (float) $row->quantity_on_hand, null, null, $note, $actor);

            return $batch->refresh();
        });
    }

    /**
     * Mengambil stok mengikuti FEFO, boleh terbagi ke beberapa batch.
     *
     * @return list<array{batch: StockBatch, quantity: float}>
     *
     * @throws PharmacyException bila stok tidak cukup
     */
    public function issue(
        int $drugId,
        int $locationId,
        float $quantity,
        string $referenceType,
        ?int $referenceId = null,
        ?User $actor = null,
    ): array {
        if ($quantity <= 0) {
            throw new PharmacyException('Jumlah yang diserahkan harus lebih dari nol.');
        }

        return DB::transaction(function () use (
            $drugId, $locationId, $quantity, $referenceType, $referenceId, $actor
        ): array {
            $tersedia = $this->availableQuantity($drugId, $locationId);

            if ($tersedia < $quantity) {
                throw new PharmacyException(sprintf(
                    'Stok tidak cukup. Tersedia %s, diminta %s.',
                    rtrim(rtrim(number_format($tersedia, 2, ',', '.'), '0'), ','),
                    rtrim(rtrim(number_format($quantity, 2, ',', '.'), '0'), ','),
                ));
            }

            $sisa = $quantity;
            $terambil = [];

            $batches = StockBatch::query()
                ->where('drug_id', $drugId)
                ->where('location_id', $locationId)
                ->usable()
                ->fefo()
                ->get();

            foreach ($batches as $batch) {
                if ($sisa <= 0) {
                    break;
                }

                $ambil = min($sisa, (float) $batch->quantity_on_hand);

                /*
                 * Syarat quantity_on_hand >= ? adalah kuncinya. Kalau ada
                 * proses lain yang lebih dulu mengambil batch ini, UPDATE
                 * mengenai nol baris dan kita lanjut ke batch berikutnya
                 * alih-alih membuat saldo minus.
                 */
                $row = DB::selectOne(
                    'UPDATE pharmacy.stock_batches
                        SET quantity_on_hand = quantity_on_hand - ?, updated_at = now()
                      WHERE id = ? AND quantity_on_hand >= ?
                  RETURNING quantity_on_hand',
                    [$ambil, $batch->id, $ambil]
                );

                if ($row === null) {
                    continue;
                }

                $this->log(
                    $batch, 'keluar', -$ambil, (float) $row->quantity_on_hand,
                    $referenceType, $referenceId, null, $actor
                );

                $terambil[] = ['batch' => $batch->refresh(), 'quantity' => $ambil];
                $sisa -= $ambil;
            }

            if ($sisa > 0) {
                // Kalah balapan dengan proses lain di tengah jalan.
                throw new PharmacyException(
                    'Stok habis diambil proses lain saat penyerahan berlangsung. Silakan ulangi.'
                );
            }

            return $terambil;
        });
    }

    /** Mengembalikan stok, mis. saat penyerahan dibatalkan. */
    public function returnStock(
        int $batchId,
        float $quantity,
        string $referenceType,
        ?int $referenceId = null,
        ?User $actor = null,
    ): void {
        DB::transaction(function () use ($batchId, $quantity, $referenceType, $referenceId, $actor): void {
            $batch = StockBatch::query()->findOrFail($batchId);

            $row = DB::selectOne(
                'UPDATE pharmacy.stock_batches
                    SET quantity_on_hand = quantity_on_hand + ?, updated_at = now()
                  WHERE id = ?
              RETURNING quantity_on_hand',
                [$quantity, $batchId]
            );

            $this->log($batch, 'retur', $quantity, (float) $row->quantity_on_hand, $referenceType, $referenceId, null, $actor);
        });
    }

    /** Stok siap pakai, tidak termasuk batch yang sudah kedaluwarsa. */
    public function availableQuantity(int $drugId, int $locationId): float
    {
        return (float) StockBatch::query()
            ->where('drug_id', $drugId)
            ->where('location_id', $locationId)
            ->usable()
            ->sum('quantity_on_hand');
    }

    /** Batch yang akan kedaluwarsa dalam rentang hari tertentu. */
    public function expiringSoon(int $locationId, int $days = 90): Collection
    {
        return StockBatch::query()
            ->with('drug')
            ->where('location_id', $locationId)
            ->where('quantity_on_hand', '>', 0)
            ->whereNotNull('expiry_date')
            ->whereBetween('expiry_date', [now()->toDateString(), now()->addDays($days)->toDateString()])
            ->orderBy('expiry_date')
            ->get();
    }

    /**
     * Menguji saldo batch terhadap buku besarnya.
     *
     * Dipakai rekonsiliasi berkala: kalau hasilnya tidak nol, ada perubahan
     * saldo yang tidak lewat kelas ini.
     */
    public function reconcile(int $batchId): array
    {
        $saldo = (float) StockBatch::query()->whereKey($batchId)->value('quantity_on_hand');

        $bukuBesar = (float) DB::table('pharmacy.stock_movements')
            ->where('batch_id', $batchId)
            ->sum('quantity');

        return [
            'saldo_tercatat' => $saldo,
            'saldo_buku_besar' => $bukuBesar,
            'selisih' => round($saldo - $bukuBesar, 2),
            'cocok' => abs($saldo - $bukuBesar) < 0.001,
        ];
    }

    private function log(
        StockBatch $batch,
        string $kind,
        float $quantity,
        float $balanceAfter,
        ?string $referenceType,
        ?int $referenceId,
        ?string $note,
        ?User $actor,
    ): void {
        DB::table('pharmacy.stock_movements')->insert([
            'moved_at' => now(),
            'batch_id' => $batch->id,
            'drug_id' => $batch->drug_id,
            'location_id' => $batch->location_id,
            'kind' => $kind,
            'quantity' => $quantity,
            'balance_after' => $balanceAfter,
            'reference_type' => $referenceType,
            'reference_id' => $referenceId,
            'note' => $note,
            'created_by' => $actor?->id,
        ]);
    }
}
