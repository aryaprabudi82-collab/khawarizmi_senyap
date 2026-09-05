<?php

namespace App\Modules\Billing\Services;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Rekap jasa medis (domain I item C).
 *
 * Enam komponen yang dibekukan pada tiap tindakan menjawab enam kelompok
 * kode laporan Khanza sekaligus:
 *
 *   share_doctor     -> harian_dokter / bulanan_dokter / rekap_jm_dokter
 *   share_paramedic  -> harian_paramedis / bulanan_paramedis
 *   share_facility   -> harian_js / bulanan_js
 *   share_kso        -> harian_kso / bulanan_kso
 *   share_management -> harian_menejemen / bulanan_menejemen
 *   share_bhp        -> harian_paket_bhp / bulanan_paket_bhp
 *
 * Semuanya dibaca lewat clinical.v_procedure_charge — kontrak terbitan
 * konteks clinical — bukan dari tabel clinical langsung.
 *
 * Angkanya sudah dibekukan saat tindakan dilakukan, jadi laporan bulan
 * lalu tidak berubah kalau tarif hari ini naik.
 */
class MedicalFeeReportService
{
    private const VIEW = 'clinical.v_procedure_charge';

    public const KOMPONEN = [
        'share_doctor' => 'Jasa Dokter',
        'share_paramedic' => 'Jasa Paramedis',
        'share_facility' => 'Jasa Sarana',
        'share_kso' => 'KSO',
        'share_management' => 'Manajemen',
        'share_bhp' => 'BHP',
    ];

    /** Ringkasan seluruh komponen pada satu rentang tanggal. */
    public function summary(string $from, string $until): Collection
    {
        $pilih = collect(self::KOMPONEN)
            ->keys()
            ->map(fn (string $k) => "coalesce(sum({$k}), 0) as {$k}")
            ->implode(', ');

        $baris = DB::table(self::VIEW)
            ->whereBetween(DB::raw('performed_at::date'), [$from, $until])
            ->selectRaw($pilih . ', count(*) as jumlah_tindakan, coalesce(sum(amount), 0) as total')
            ->first();

        return collect(self::KOMPONEN)->map(fn (string $label, string $kolom) => [
            'label' => $label,
            'jumlah' => (float) ($baris->{$kolom} ?? 0),
        ])->values()->push([
            'label' => 'Total tindakan',
            'jumlah' => (float) ($baris->total ?? 0),
        ]);
    }

    /**
     * Rekap per pelaksana untuk satu komponen — inti rekap_jm_dokter dan
     * padanannya untuk paramedis.
     */
    public function perPractitioner(string $component, string $from, string $until): Collection
    {
        $this->assertComponent($component);

        return DB::table(self::VIEW)
            ->whereBetween(DB::raw('performed_at::date'), [$from, $until])
            ->whereNotNull('practitioner_id')
            ->where($component, '>', 0)
            ->groupBy('practitioner_id', 'practitioner_name')
            ->selectRaw('practitioner_id, practitioner_name, count(*) as jumlah_tindakan, sum(' . $component . ') as jumlah')
            ->orderByDesc('jumlah')
            ->get();
    }

    /** Rincian harian satu komponen — dasar seluruh kode harian_*. */
    public function daily(string $component, string $from, string $until): Collection
    {
        $this->assertComponent($component);

        return DB::table(self::VIEW)
            ->whereBetween(DB::raw('performed_at::date'), [$from, $until])
            ->where($component, '>', 0)
            ->groupBy(DB::raw('performed_at::date'))
            ->selectRaw('performed_at::date as tanggal, count(*) as jumlah_tindakan, sum(' . $component . ') as jumlah')
            ->orderBy('tanggal')
            ->get();
    }

    /** Rincian bulanan satu komponen — dasar seluruh kode bulanan_*. */
    public function monthly(string $component, int $year): Collection
    {
        $this->assertComponent($component);

        return DB::table(self::VIEW)
            ->whereRaw('extract(year from performed_at) = ?', [$year])
            ->where($component, '>', 0)
            ->groupBy(DB::raw('extract(month from performed_at)'))
            ->selectRaw('extract(month from performed_at) as bulan, count(*) as jumlah_tindakan, sum(' . $component . ') as jumlah')
            ->orderBy('bulan')
            ->get();
    }

    /**
     * Nama kolom masuk ke SQL mentah, jadi ia wajib berasal dari daftar
     * yang dikenal — bukan dari apa pun yang dikirim pengguna.
     */
    private function assertComponent(string $component): void
    {
        if (! array_key_exists($component, self::KOMPONEN)) {
            throw new BillingException("Komponen jasa '{$component}' tidak dikenal.");
        }
    }
}
