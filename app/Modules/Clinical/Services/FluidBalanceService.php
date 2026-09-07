<?php

namespace App\Modules\Clinical\Services;

use App\Modules\Clinical\Models\FluidBalanceEntry;
use App\Modules\Platform\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Keseimbangan cairan (domain M item E) — 2 kode: balance_cairan dan
 * catatan_cairan_hemodialisa.
 *
 * SALDONYA DIHITUNG, TIDAK PERNAH DISIMPAN. Khanza menyimpan kolom
 * `keseimbangan` di samping komponennya; kalau salah satu komponen
 * diperbaiki, saldo yang tersimpan menjadi basi tanpa terlihat salah.
 * Aturan "saldo tidak disimpan" sudah berlaku di proyek ini sejak domain K
 * untuk hutang dan piutang — di sini akibatnya lebih berat: keseimbangan
 * cairan yang keliru pada pasien gagal jantung atau gagal ginjal adalah
 * keputusan klinis yang keliru.
 *
 * ARAH DISALIN DARI JENIS CAIRANNYA, bukan diterima dari pemanggil. Arah
 * yang boleh dikirim terpisah membuka celah urine tercatat sebagai asupan,
 * dan saldo yang dihasilkan akan tampak wajar sambil sepenuhnya keliru.
 *
 * IWL DICATAT SEBAGAI BARIS BIASA, bukan dihitung diam-diam. Insensible
 * water loss memang diperkirakan dari berat badan, tapi rumusnya berbeda
 * antar pedoman dan antar keadaan (demam menambahnya). Menghitungnya
 * sendiri berarti memasukkan angka yang tidak pernah dinyatakan siapa pun
 * ke dalam saldo yang dipakai memutuskan pemberian cairan.
 */
class FluidBalanceService
{
    private const MASTER = 'catalog.v_fluid_item';

    /**
     * Mencatat satu cairan masuk atau keluar.
     *
     * @throws ClinicalException
     */
    public function record(
        int $registrationId,
        string $itemCode,
        float $volumeMl,
        ?Carbon $recordedAt = null,
        ?string $note = null,
        ?User $actor = null,
    ): FluidBalanceEntry {
        $kunjungan = DB::table('encounter.v_registration_summary')->where('id', $registrationId)->first()
            ?? throw new ClinicalException('Kunjungan tidak ditemukan atau sudah dibatalkan.');

        if ($volumeMl <= 0) {
            throw new ClinicalException(
                'Volume harus lebih dari nol. Arah masuk atau keluar ditentukan jenis cairannya, '
                . 'bukan tanda angkanya.'
            );
        }

        $jenis = DB::table(self::MASTER)->where('code', $itemCode)->first()
            ?? throw new ClinicalException("Jenis cairan '{$itemCode}' tidak ada di master.");

        if (! $jenis->is_active) {
            throw new ClinicalException("Jenis cairan '{$jenis->name}' sudah tidak aktif.");
        }

        return FluidBalanceEntry::query()->create([
            'registration_id' => $registrationId,
            'patient_id' => $kunjungan->patient_id,
            'item_code' => $jenis->code,
            'item_name' => $jenis->name,
            // Arah disalin dari jenisnya — lihat catatan kelas.
            'direction' => $jenis->direction,
            'volume_ml' => $volumeMl,
            'note' => $note,
            'recorded_by' => $actor?->id,
            'recorded_by_name' => $actor?->name,
            'recorded_at' => $recordedAt ?? now(),
        ]);
    }

    /**
     * Keseimbangan cairan pada satu rentang waktu.
     *
     * Dihitung setiap kali diminta. Rinciannya ikut dikembalikan supaya
     * angka saldonya bisa ditelusuri — saldo tanpa rincian adalah angka
     * yang harus dipercaya begitu saja.
     *
     * @return object{masuk: float, keluar: float, balance: float, rincian: Collection}
     */
    public function balance(int $registrationId, ?Carbon $from = null, ?Carbon $until = null): object
    {
        $baris = FluidBalanceEntry::query()
            ->where('registration_id', $registrationId)
            ->when($from, fn ($q) => $q->where('recorded_at', '>=', $from))
            ->when($until, fn ($q) => $q->where('recorded_at', '<=', $until))
            ->get();

        $masuk = (float) $baris->where('direction', FluidBalanceEntry::MASUK)->sum('volume_ml');
        $keluar = (float) $baris->where('direction', FluidBalanceEntry::KELUAR)->sum('volume_ml');

        return (object) [
            'masuk' => round($masuk, 2),
            'keluar' => round($keluar, 2),
            // Positif berarti retensi cairan, negatif berarti defisit.
            'balance' => round($masuk - $keluar, 2),
            'rincian' => $baris
                ->groupBy('item_name')
                ->map(fn ($g) => (object) [
                    'direction' => $g->first()->direction,
                    'volume_ml' => round((float) $g->sum('volume_ml'), 2),
                    'jumlah_catatan' => $g->count(),
                ])
                ->sortBy('direction'),
        ];
    }

    /**
     * Keseimbangan per hari — bentuk yang dipakai memutuskan pemberian
     * cairan besok, karena yang menentukan bukan total sejak masuk
     * melainkan saldo hari itu.
     */
    public function dailyBalance(int $registrationId, int $days = 7): Collection
    {
        return DB::table('clinical.fluid_balance_entries')
            ->where('registration_id', $registrationId)
            ->where('recorded_at', '>=', now()->subDays($days)->startOfDay())
            ->selectRaw("date(recorded_at) AS tanggal,
                         coalesce(sum(volume_ml) FILTER (WHERE direction = 'masuk'), 0) AS masuk,
                         coalesce(sum(volume_ml) FILTER (WHERE direction = 'keluar'), 0) AS keluar")
            ->groupByRaw('date(recorded_at)')
            ->orderByDesc('tanggal')
            ->get()
            ->map(fn ($b) => (object) [
                'tanggal' => $b->tanggal,
                'masuk' => (float) $b->masuk,
                'keluar' => (float) $b->keluar,
                'balance' => round((float) $b->masuk - (float) $b->keluar, 2),
            ]);
    }

    /** Jenis cairan yang tersedia, boleh disaring konteks perawatannya. */
    public function items(?string $careContext = null): Collection
    {
        return DB::table(self::MASTER)
            ->where('is_active', true)
            ->when($careContext, fn ($q) => $q->where(
                fn ($w) => $w->where('care_context', $careContext)->orWhereNull('care_context')
            ))
            ->orderBy('direction')
            ->orderBy('sequence')
            ->get();
    }

    /**
     * @throws ClinicalException
     */
    public function remove(FluidBalanceEntry $entry): void
    {
        $entry->delete();
    }
}
