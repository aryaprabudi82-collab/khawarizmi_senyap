<?php

namespace App\Modules\Reporting\Services;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Indikator mutu & lama pelayanan (domain J item D) — 13 kode.
 *
 *   bedEfficiency      -> hitung_bor, hitung_alos (BTO & TOI ikut, karena
 *                         keempatnya satu rumus efisiensi ranap yang dibaca
 *                         bersamaan — Barber-Johnson)
 *   outpatientDuration -> lama_pelayanan_ralan, lama_pelayanan_poli,
 *                         lama_pelayanan_pasien
 *   pharmacyDuration   -> lama_pelayanan_apotek
 *   orderDuration      -> lama_pelayanan_radiologi, lama_pelayanan_lab
 *                         (+ varian Lab PA; Lab MB belum punya kategorinya)
 *   operationDuration  -> lama_operasi
 *   cssdDuration       -> lama_pelayanan_cssd
 *
 * ATURAN YANG DIPEGANG SELURUH KELAS INI: durasi selalu DIHITUNG dari dua
 * stempel waktu, tidak pernah disimpan. Durasi yang disimpan akan basi
 * diam-diam begitu salah satu stempelnya diperbaiki.
 *
 * Baris yang tahapannya belum lengkap DIKECUALIKAN dari rata-rata, bukan
 * dihitung sebagai nol. Nol berarti "dilayani seketika" dan akan menarik
 * turun rata-rata sampai indikator mutu terlihat jauh lebih baik daripada
 * kenyataannya — kesalahan yang menguntungkan pelapor, jadi justru yang
 * paling perlu dijaga. Jumlah yang belum lengkap ikut dilaporkan supaya
 * ketahuan kalau yang bermasalah sebenarnya pencatatannya.
 */
class QualityIndicatorReportService
{
    private const REGISTRASI = 'encounter.v_registration_summary';
    private const ADMISI = 'inpatient.v_admission_summary';
    private const BED = 'inpatient.v_bed_availability';
    private const PENEMPATAN = 'inpatient.v_bed_assignment';
    private const RESEP = 'pharmacy.v_prescription_duration';
    private const ORDER = 'orders.v_order_summary';
    private const OPERASI = 'clinical.v_operation_summary';
    private const CSSD = 'asset.v_cssd_circulation';

    /** Menit antara dua stempel waktu, sebagai ekspresi SQL. */
    private function menit(string $dari, string $sampai): string
    {
        return "extract(epoch from ({$sampai} - {$dari})) / 60.0";
    }

    /**
     * Ringkasan satu tahap: rata-rata, median, dan terlama — plus berapa
     * baris yang tahapannya belum lengkap sehingga tidak ikut dihitung.
     */
    private function ringkas($query, string $dari, string $sampai, string $label): object
    {
        $m = $this->menit($dari, $sampai);

        $row = $query->selectRaw("
            count(*) FILTER (WHERE {$dari} IS NOT NULL AND {$sampai} IS NOT NULL) AS terhitung,
            count(*) FILTER (WHERE {$dari} IS NULL OR {$sampai} IS NULL) AS belum_lengkap,
            round(avg({$m})::numeric, 1) AS rata_menit,
            round((percentile_cont(0.5) WITHIN GROUP (ORDER BY {$m}))::numeric, 1) AS median_menit,
            round(max({$m})::numeric, 1) AS terlama_menit
        ")->first();

        return (object) [
            'tahap' => $label,
            'terhitung' => (int) ($row->terhitung ?? 0),
            'belum_lengkap' => (int) ($row->belum_lengkap ?? 0),
            'rata_menit' => $row->rata_menit,
            'median_menit' => $row->median_menit,
            'terlama_menit' => $row->terlama_menit,
        ];
    }

    // --------------------------------------------------------- efisiensi ranap

    /**
     * BOR, ALOS, BTO, TOI — keempat indikator Barber-Johnson.
     *
     * Hari-rawat dihitung dari penempatan bed yang sungguh terjadi, BUKAN
     * dari jumlah admisi, supaya pindah kamar di tengah rawat tidak
     * terhitung sebagai dua pasien.
     */
    public function bedEfficiency(string $from, string $until): object
    {
        $hari = max(1, (int) DB::selectOne('SELECT (?::date - ?::date) + 1 AS n', [$until, $from])->n);

        $tempatTidur = (int) (DB::table(self::BED)->sum('jumlah') ?: 0);

        // Hari-rawat: irisan tiap penempatan bed dengan rentang yang diminta.
        $hariRawat = (float) (DB::table(self::PENEMPATAN)
            ->whereRaw('assigned_at::date <= ?::date', [$until])
            ->whereRaw('coalesce(released_at, now())::date >= ?::date', [$from])
            ->selectRaw('coalesce(sum(
                least(coalesce(released_at, now())::date, ?::date)
                - greatest(assigned_at::date, ?::date) + 1
            ), 0) AS n', [$until, $from])
            ->value('n') ?: 0);

        $pulang = DB::table(self::ADMISI)
            ->whereNotNull('discharged_at')
            ->whereRaw('discharged_at::date BETWEEN ?::date AND ?::date', [$from, $until])
            ->selectRaw('count(*) AS jumlah,
                         coalesce(sum(greatest(1, (discharged_at::date - admitted_at::date))), 0) AS lama')
            ->first();

        $jumlahPulang = (int) ($pulang->jumlah ?? 0);
        $totalLama = (float) ($pulang->lama ?? 0);

        $kapasitas = $tempatTidur * $hari;

        return (object) [
            'hari' => $hari,
            'tempat_tidur' => $tempatTidur,
            'hari_rawat' => $hariRawat,
            'pasien_keluar' => $jumlahPulang,
            // BOR: persen tempat tidur terisi. Standar Kemenkes 60-85%.
            'bor' => $kapasitas > 0 ? round($hariRawat / $kapasitas * 100, 1) : null,
            // ALOS: rata-rata lama dirawat. Standar 6-9 hari.
            'alos' => $jumlahPulang > 0 ? round($totalLama / $jumlahPulang, 1) : null,
            // BTO: berapa kali satu tempat tidur dipakai pada rentang ini.
            'bto' => $tempatTidur > 0 ? round($jumlahPulang / $tempatTidur, 1) : null,
            // TOI: rata-rata tempat tidur kosong antar pasien. Standar 1-3 hari.
            'toi' => $jumlahPulang > 0 ? round(($kapasitas - $hariRawat) / $jumlahPulang, 1) : null,
        ];
    }

    // ---------------------------------------------------------- lama pelayanan

    /**
     * Lama pelayanan rawat jalan, per tahap.
     *
     * Waktu tunggu (daftar → dipanggil) adalah indikator SPM Kemenkes
     * dengan standar <= 60 menit, jadi ia dilaporkan TERPISAH dari lama
     * pelayanan alih-alih dilebur jadi satu angka total yang menyembunyikan
     * di mana antreannya sebenarnya menumpuk.
     */
    public function outpatientDuration(string $from, string $until, ?string $unitName = null): Collection
    {
        $tahap = [
            ['registered_at', 'called_at', 'Waktu tunggu (daftar → dipanggil)'],
            ['called_at', 'served_at', 'Mulai dilayani (dipanggil → dilayani)'],
            ['served_at', 'finished_at', 'Lama tindakan (dilayani → selesai)'],
            ['registered_at', 'finished_at', 'Total di rumah sakit'],
        ];

        return collect($tahap)->map(fn ($t) => $this->ringkas(
            $this->outpatientQuery($from, $until, $unitName), $t[0], $t[1], $t[2]
        ));
    }

    /** Rincian per unit — lewat builder bersama yang sama, supaya penyaringnya tidak terlewat. */
    public function outpatientByUnit(string $from, string $until, ?string $unitName = null): Collection
    {
        $tunggu = $this->menit('registered_at', 'called_at');

        return $this->outpatientQuery($from, $until, $unitName)
            ->groupBy('unit_name')
            ->selectRaw("unit_name,
                         count(*) AS kunjungan,
                         count(*) FILTER (WHERE called_at IS NOT NULL) AS terhitung,
                         round(avg({$tunggu})::numeric, 1) AS rata_tunggu_menit,
                         count(*) FILTER (WHERE called_at IS NOT NULL AND {$tunggu} > 60) AS lewat_spm")
            ->orderByDesc('kunjungan')
            ->get();
    }

    /**
     * Kepatuhan SPM waktu tunggu rawat jalan (<= 60 menit).
     *
     * Yang dilaporkan proporsi kunjungan yang TERCATAT lengkap, dan jumlah
     * yang belum tercatat disebut berdampingan — supaya kepatuhan tinggi
     * yang sebetulnya berasal dari pencatatan yang bolong tidak terbaca
     * sebagai prestasi.
     */
    public function waitingTimeCompliance(string $from, string $until, ?string $unitName = null): object
    {
        $tunggu = $this->menit('registered_at', 'called_at');

        $row = $this->outpatientQuery($from, $until, $unitName)
            ->selectRaw("count(*) AS kunjungan,
                         count(*) FILTER (WHERE called_at IS NOT NULL) AS terhitung,
                         count(*) FILTER (WHERE called_at IS NOT NULL AND {$tunggu} <= 60) AS patuh")
            ->first();

        $kunjungan = (int) ($row->kunjungan ?? 0);
        $terhitung = (int) ($row->terhitung ?? 0);

        return (object) [
            'kunjungan' => $kunjungan,
            'terhitung' => $terhitung,
            'belum_tercatat' => $kunjungan - $terhitung,
            'patuh' => (int) ($row->patuh ?? 0),
            'persen' => $terhitung > 0 ? round((int) $row->patuh / $terhitung * 100, 1) : null,
        ];
    }

    public function pharmacyDuration(string $from, string $until): Collection
    {
        $tahap = [
            ['submitted_at', 'reviewed_at', 'Telaah apoteker (diserahkan → ditelaah)'],
            ['reviewed_at', 'dispensed_at', 'Penyiapan obat (ditelaah → diserahkan)'],
            ['submitted_at', 'dispensed_at', 'Total pelayanan apotek'],
        ];

        return collect($tahap)->map(fn ($t) => $this->ringkas(
            DB::table(self::RESEP)->whereRaw('prescribed_at::date BETWEEN ?::date AND ?::date', [$from, $until]),
            $t[0], $t[1], $t[2]
        ));
    }

    public function orderDuration(string $category, string $from, string $until): Collection
    {
        $tahap = [
            ['requested_at', 'processed_at', 'Mulai dikerjakan (diminta → diproses)'],
            ['processed_at', 'resulted_at', 'Pemeriksaan (diproses → ada hasil)'],
            ['resulted_at', 'verified_at', 'Verifikasi (hasil → diverifikasi)'],
            ['requested_at', 'verified_at', 'Total waktu tunggu hasil'],
        ];

        return collect($tahap)->map(fn ($t) => $this->ringkas(
            DB::table(self::ORDER)
                ->where('category', $category)
                ->whereRaw('requested_at::date BETWEEN ?::date AND ?::date', [$from, $until]),
            $t[0], $t[1], $t[2]
        ));
    }

    public function operationDuration(string $from, string $until): Collection
    {
        return collect([$this->ringkas(
            DB::table(self::OPERASI)->whereRaw('performed_at::date BETWEEN ?::date AND ?::date', [$from, $until]),
            'started_at', 'finished_at', 'Lama operasi (insisi → selesai)'
        )]);
    }

    public function cssdDuration(string $from, string $until): Collection
    {
        $tahap = [
            ['received_at', 'processed_at', 'Mulai diproses (diterima → diproses)'],
            ['processed_at', 'sterilized_at', 'Sterilisasi (diproses → steril)'],
            ['sterilized_at', 'distributed_at', 'Distribusi (steril → didistribusikan)'],
            ['received_at', 'distributed_at', 'Total siklus CSSD'],
        ];

        return collect($tahap)->map(fn ($t) => $this->ringkas(
            DB::table(self::CSSD)->whereRaw('received_at::date BETWEEN ?::date AND ?::date', [$from, $until]),
            $t[0], $t[1], $t[2]
        ));
    }

    /** Daftar unit yang punya kunjungan pada rentang ini — untuk isian penyaring. */
    public function units(string $from, string $until): Collection
    {
        return DB::table(self::REGISTRASI)
            ->whereBetween('service_date', [$from, $until])
            ->distinct()
            ->orderBy('unit_name')
            ->pluck('unit_name');
    }

    /**
     * Builder bersama seluruh potongan rawat jalan.
     *
     * Kunjungan 'tidak-hadir' DIKECUALIKAN: pasien yang tidak datang saat
     * dipanggil tidak punya waktu tunggu, dan memasukkannya berarti
     * mengukur ketidakhadiran pasien sebagai kelambatan rumah sakit.
     */
    private function outpatientQuery(string $from, string $until, ?string $unitName)
    {
        $q = DB::table(self::REGISTRASI)
            ->whereBetween('service_date', [$from, $until])
            ->where('status', '<>', 'tidak-hadir');

        if ($unitName !== null && $unitName !== '') {
            $q->where('unit_name', $unitName);
        }

        return $q;
    }
}
