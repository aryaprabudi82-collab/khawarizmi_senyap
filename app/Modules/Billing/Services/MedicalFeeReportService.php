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

    /**
     * Tiga kode fee_* ternyata bukan jenis fee baru, melainkan potongan
     * berbeda dari jasa dokter yang sudah dibekukan:
     *
     *   fee_ralan         -> careType 'ralan'
     *   fee_visit_dokter  -> careType 'ranap'
     *   fee_bacaan_ekg    -> serviceCode layanan EKG
     *
     * Jenis rawat diambil dengan menyambungkan ke encounter.v_registration_summary
     * lewat registration_id — sesama kontrak terbitan yang memang sudah
     * dibaca billing, bukan menyentuh tabel encounter langsung.
     */
    private function baseQuery(string $from, string $until, ?string $careType = null, ?string $serviceCode = null): \Illuminate\Database\Query\Builder
    {
        $query = DB::table(self::VIEW . ' as p')
            ->whereBetween(DB::raw('p.performed_at::date'), [$from, $until]);

        if ($careType !== null) {
            $query->join('encounter.v_registration_summary as r', 'r.id', '=', 'p.registration_id')
                ->where('r.care_type', $careType);
        }

        if ($serviceCode !== null) {
            $query->where('p.service_code', $serviceCode);
        }

        return $query;
    }

    /** Ringkasan seluruh komponen pada satu rentang tanggal. */
    public function summary(string $from, string $until, ?string $careType = null, ?string $serviceCode = null): Collection
    {
        $pilih = collect(self::KOMPONEN)
            ->keys()
            ->map(fn (string $k) => "coalesce(sum(p.{$k}), 0) as {$k}")
            ->implode(', ');

        $baris = $this->baseQuery($from, $until, $careType, $serviceCode)
            ->selectRaw($pilih . ', count(*) as jumlah_tindakan, coalesce(sum(p.amount), 0) as total')
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
    public function perPractitioner(string $component, string $from, string $until, ?string $careType = null, ?string $serviceCode = null): Collection
    {
        $this->assertComponent($component);

        return $this->baseQuery($from, $until, $careType, $serviceCode)
            ->whereNotNull('p.practitioner_id')
            ->where('p.' . $component, '>', 0)
            ->groupBy('p.practitioner_id', 'p.practitioner_name')
            ->selectRaw('p.practitioner_id, p.practitioner_name, count(*) as jumlah_tindakan, sum(p.' . $component . ') as jumlah')
            ->orderByDesc('jumlah')
            ->get();
    }

    /** Rincian harian satu komponen — dasar seluruh kode harian_*. */
    public function daily(string $component, string $from, string $until, ?string $careType = null, ?string $serviceCode = null): Collection
    {
        $this->assertComponent($component);

        return $this->baseQuery($from, $until, $careType, $serviceCode)
            ->where('p.' . $component, '>', 0)
            ->groupBy(DB::raw('p.performed_at::date'))
            ->selectRaw('p.performed_at::date as tanggal, count(*) as jumlah_tindakan, sum(p.' . $component . ') as jumlah')
            ->orderBy('tanggal')
            ->get();
    }

    /** Rincian bulanan satu komponen — dasar seluruh kode bulanan_*. */
    public function monthly(string $component, int $year, ?string $careType = null, ?string $serviceCode = null): Collection
    {
        $this->assertComponent($component);

        return $this->baseQuery($year . '-01-01', $year . '-12-31', $careType, $serviceCode)
            ->where('p.' . $component, '>', 0)
            ->groupBy(DB::raw('extract(month from p.performed_at)'))
            ->selectRaw('extract(month from p.performed_at) as bulan, count(*) as jumlah_tindakan, sum(p.' . $component . ') as jumlah')
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
