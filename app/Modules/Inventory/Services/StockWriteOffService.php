<?php

namespace App\Modules\Inventory\Services;

use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\StockWriteOff;
use App\Modules\Inventory\Models\StockWriteOffItem;
use App\Modules\Platform\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Penghapusan stok rusak, kedaluwarsa, dan hilang (domain N item C).
 *
 * LIMA ATURAN.
 *
 * 1. STOK BARU BERKURANG SETELAH DISETUJUI, bukan saat diajukan.
 *    Pengajuan yang langsung memotong stok membuat barang hilang dari
 *    gudang sebelum ada yang membenarkan hilangnya — dan penolakan
 *    kemudian harus mengembalikannya, satu langkah lagi yang bisa gagal.
 *
 * 2. YANG MENYETUJUI HARUS ORANG LAIN. Membuang barang adalah peristiwa
 *    keuangan; petugas yang bisa mengajukan sekaligus menyetujui sendiri
 *    berarti tidak ada pemisahan sama sekali, dan "rusak" jadi tempat
 *    paling mudah menyembunyikan stok yang hilang.
 *
 * 3. HARGA DIBEKUKAN SAAT PENGHAPUSAN. Kalau tidak disebutkan, diambil
 *    dari harga pembelian terakhir yang diketahui; kalau memang tidak
 *    ada riwayatnya, nol DENGAN CATATAN — bukan angka karangan.
 *
 * 4. ALASANNYA DIBEDAKAN. Rusak menunjuk masalah penyimpanan,
 *    kedaluwarsa menunjuk masalah perencanaan, hilang menunjuk masalah
 *    pengamanan. Jawabannya menentukan tindakan yang berbeda.
 *
 * 5. BARANG KEDALUWARSA MENYEBUT TANGGAL KEDALUWARSANYA. Pertanyaan
 *    berikutnya selalu "sejak kapan ia menganggur di gudang", dan tanpa
 *    tanggalnya pertanyaan itu tidak bisa dijawab.
 */
class StockWriteOffService
{
    public function __construct(
        private readonly StockLedger $ledger,
        private readonly NumberAllocator $numbers,
    ) {}

    /**
     * Mengajukan penghapusan.
     *
     * @param  array<int, array<string, mixed>>  $lines
     *
     * @throws InventoryException
     */
    public function propose(string $reason, string $detail, array $lines, ?User $actor = null, array $data = []): StockWriteOff
    {
        if (! array_key_exists($reason, StockWriteOff::ALASAN)) {
            throw new InventoryException(
                "Alasan penghapusan '{$reason}' tidak dikenali. Pilihannya: "
                .implode(', ', array_keys(StockWriteOff::ALASAN)).'.'
            );
        }

        $uraian = trim($detail);

        if ($uraian === '') {
            throw new InventoryException(
                'Uraian alasan wajib diisi. "Rusak" saja tidak menjelaskan apa yang terjadi, dan '
                .'penghapusan stok justru yang ditinjau saat kerugian gudang dipertanyakan.'
            );
        }

        if ($lines === []) {
            throw new InventoryException('Setidaknya satu barang wajib disebut.');
        }

        $pengaju = trim($data['requested_by_name'] ?? $actor?->name ?? '');

        if ($pengaju === '') {
            throw new InventoryException('Nama pengaju wajib dicatat.');
        }

        return DB::transaction(function () use ($reason, $uraian, $lines, $actor, $data, $pengaju): StockWriteOff {
            $penghapusan = StockWriteOff::query()->create([
                'write_off_number' => $this->numbers->allocate('WO'),
                'write_off_date' => $data['write_off_date'] ?? now()->toDateString(),
                'reason' => $reason,
                'reason_detail' => $uraian,
                'unit_id' => $data['unit_id'] ?? null,
                'unit_name' => $data['unit_name'] ?? null,
                'disposal_method' => $data['disposal_method'] ?? null,
                'decision_number' => $data['decision_number'] ?? null,
                'status' => StockWriteOff::DIAJUKAN,
                'requested_by' => $actor?->id,
                'requested_by_name' => $pengaju,
            ]);

            $nilai = 0.0;

            foreach ($lines as $baris) {
                $nilai += $this->addLine($penghapusan, $reason, $baris);
            }

            $penghapusan->update(['total_value' => round($nilai, 2)]);

            return $penghapusan->refresh();
        });
    }

    /**
     * Menyetujui penghapusan — dan baru di sinilah stok berkurang.
     *
     * @throws InventoryException
     */
    public function approve(StockWriteOff $writeOff, User $approver): StockWriteOff
    {
        if (! $writeOff->isPending()) {
            throw new InventoryException("Penghapusan {$writeOff->write_off_number} sudah diputuskan.");
        }

        if ($writeOff->requested_by !== null && $writeOff->requested_by === $approver->id) {
            throw new InventoryException(
                'Pengaju tidak bisa menyetujui pengajuannya sendiri. Membuang barang adalah peristiwa '
                .'keuangan, dan tanpa pemisahan itu "rusak" jadi tempat paling mudah menyembunyikan stok '
                .'yang hilang.'
            );
        }

        return DB::transaction(function () use ($writeOff, $approver): StockWriteOff {
            foreach ($writeOff->items as $baris) {
                $this->ledger->issue(
                    $baris->item_id,
                    (float) $baris->quantity,
                    $writeOff->reason,
                    'stock_write_off',
                    $writeOff->id,
                    $approver,
                    trim($writeOff->reason_detail),
                );
            }

            $writeOff->update([
                'status' => StockWriteOff::DISETUJUI,
                'approved_by' => $approver->id,
                'approved_by_name' => $approver->name,
                'decided_at' => now(),
            ]);

            return $writeOff->refresh();
        });
    }

    /**
     * @throws InventoryException
     */
    public function reject(StockWriteOff $writeOff, string $reason, ?User $actor = null): StockWriteOff
    {
        if (! $writeOff->isPending()) {
            throw new InventoryException("Penghapusan {$writeOff->write_off_number} sudah diputuskan.");
        }

        $alasan = trim($reason);

        if ($alasan === '') {
            throw new InventoryException('Alasan penolakan wajib diisi.');
        }

        $writeOff->update([
            'status' => StockWriteOff::DITOLAK,
            'rejection_reason' => $alasan,
            'approved_by' => $actor?->id,
            'approved_by_name' => $actor?->name,
            'decided_at' => now(),
        ]);

        return $writeOff->refresh();
    }

    // ---------------------------------------------------------------- baca

    /**
     * Rekap nilai yang dibuang per alasan sepanjang satu periode.
     *
     * Inilah yang tidak bisa disusun sebelumnya: barang rusak tercatat
     * sebagai pengeluaran biasa, tak terbedakan dari yang dipakai
     * melayani pasien.
     *
     * @return array<string, array{jumlah: int, nilai: float}>
     */
    public function recapByReason(string $from, string $until): array
    {
        $rekap = [];

        foreach (array_keys(StockWriteOff::ALASAN) as $alasan) {
            $rekap[$alasan] = ['jumlah' => 0, 'nilai' => 0.0];
        }

        StockWriteOff::query()
            ->where('status', StockWriteOff::DISETUJUI)
            ->whereBetween('write_off_date', [$from, $until])
            ->get()
            ->each(function (StockWriteOff $w) use (&$rekap) {
                $rekap[$w->reason]['jumlah']++;
                $rekap[$w->reason]['nilai'] += (float) $w->total_value;
            });

        foreach ($rekap as $alasan => $isi) {
            $rekap[$alasan]['nilai'] = round($isi['nilai'], 2);
        }

        return $rekap;
    }

    public function pending(): Collection
    {
        return StockWriteOff::query()
            ->where('status', StockWriteOff::DIAJUKAN)
            ->with('items')
            ->orderBy('write_off_date')
            ->get();
    }

    // ------------------------------------------------------------ internal

    /**
     * @param  array<string, mixed>  $line
     *
     * @throws InventoryException
     */
    private function addLine(StockWriteOff $writeOff, string $reason, array $line): float
    {
        $item = Item::query()->find($line['item_id'] ?? 0)
            ?? throw new InventoryException('Barang tidak ditemukan.');

        $jumlah = (float) ($line['quantity'] ?? 0);

        if ($jumlah <= 0) {
            throw new InventoryException("Jumlah {$item->name} harus lebih dari nol.");
        }

        if ($jumlah > (float) $item->quantity_on_hand) {
            throw new InventoryException(sprintf(
                'Tidak bisa menghapus %s %s: stoknya cuma %s. Selisih sebanyak ini menandakan saldo '
                .'gudangnya sendiri sudah salah, dan itu urusan stok opname, bukan penghapusan.',
                $jumlah, $item->name, $item->quantity_on_hand,
            ));
        }

        $kedaluwarsa = $line['expires_on'] ?? null;

        if ($reason === StockWriteOff::KEDALUWARSA && blank($kedaluwarsa)) {
            throw new InventoryException(
                "Tanggal kedaluwarsa {$item->name} wajib disebut. Pertanyaan berikutnya selalu sejak "
                .'kapan barangnya menganggur di gudang, dan tanpa tanggalnya tidak bisa dijawab.'
            );
        }

        $harga = array_key_exists('unit_price', $line)
            ? (float) $line['unit_price']
            : $this->lastKnownPrice($item->id);

        $nilai = round($jumlah * $harga, 2);

        StockWriteOffItem::query()->create([
            'write_off_id' => $writeOff->id,
            'item_id' => $item->id,
            // Disalin saat pengajuan: nama barang boleh berubah, yang
            // tercatat sebagai kerugian tidak.
            'item_name' => $item->name,
            'quantity' => $jumlah,
            'unit_price' => $harga,
            'amount' => $nilai,
            'batch_number' => $line['batch_number'] ?? null,
            'expires_on' => $kedaluwarsa,
            'note' => $line['note'] ?? ($harga <= 0
                ? 'Harga pembelian tidak diketahui; nilai kerugian belum bisa dihitung.'
                : null),
        ]);

        return $nilai;
    }

    /**
     * Harga pembelian terakhir yang diketahui untuk sebuah barang.
     *
     * inventory.items memang TIDAK menyimpan harga — harganya hidup pada
     * baris pesanan pembelian. Mengembalikan nol saat tidak ada
     * riwayatnya, dan barisnya diberi catatan: nol yang jujur lebih baik
     * daripada angka karangan pada catatan kerugian.
     */
    private function lastKnownPrice(int $itemId): float
    {
        $harga = DB::table('inventory.purchase_order_items')
            ->where('item_id', $itemId)
            ->orderByDesc('id')
            ->value('unit_price');

        return $harga === null ? 0.0 : (float) $harga;
    }
}
