<?php

namespace App\Modules\Catalog\Services;

use App\Modules\Catalog\Models\FormTemplate;
use App\Modules\ReadinessCheck;
use Illuminate\Support\Facades\DB;

/**
 * Syarat kesiapan konteks catalog — isi klinis yang menentukan apakah
 * layar-layar rekam medis benar-benar bisa dipakai.
 *
 * DITEMUKAN SAAT VERIFIKASI DOMAIN M. Mekanismenya seluruhnya terbangun
 * (mesin formulir berversi, katalog pengukuran, master keperawatan), tapi
 * sebagian ISInya kosong dan tidak ada layar untuk mengisinya. Tabel yang
 * ada tapi kosong, tanpa jalan mengisi, bukan fitur yang selesai — ia layar
 * yang terbuka lalu tidak menawarkan pilihan apa pun.
 */
class CatalogReadiness implements ReadinessCheck
{
    public function readinessItems(): array
    {
        return array_merge(
            $this->periksaMasterKeperawatan(),
            $this->periksaTemplateAsesmen(),
        );
    }

    /**
     * Masalah & rencana keperawatan (SDKI/SIKI).
     *
     * Delapan kode `master_masalah_keperawatan_*` dan delapan
     * `master_rencana_keperawatan_*` Khanza dinaungi dua tabel berkolom
     * spesialisasi di sini. Tabelnya ada, mekanismenya ada — tapi ISInya
     * nol, tidak ada seeder, dan tidak ada layar untuk mengisinya.
     *
     * @return list<array{judul: string, status: string, akibat: string}>
     */
    private function periksaMasterKeperawatan(): array
    {
        $masalah = DB::table('catalog.nursing_problems')->count();
        $rencana = DB::table('catalog.nursing_care_plans')->count();

        if ($masalah > 0 && $rencana > 0) {
            return [[
                'judul' => 'Master keperawatan (SDKI/SIKI)',
                'status' => self::BERES,
                'akibat' => $masalah.' masalah, '.$rencana.' rencana keperawatan terdaftar.',
            ]];
        }

        return [[
            'judul' => 'Master keperawatan (SDKI/SIKI)',
            'status' => self::MENGHALANGI,
            'akibat' => 'Masalah keperawatan ('.$masalah.') dan rencana keperawatan ('.$rencana
                .') masih kosong, dan BELUM ADA layar untuk mengisinya. Perawat tidak bisa '
                .'menegakkan diagnosis keperawatan maupun menyusun rencana asuhan sama '
                .'sekali — layarnya terbuka lalu tidak menawarkan pilihan apa pun. Daftar '
                .'resminya SDKI/SIKI milik PPNI; isinya keputusan komite keperawatan RSP UI, '
                .'tapi jalan memasukkannya belum dibangun.',
        ]];
    }

    /**
     * Template asesmen medis per spesialisasi.
     *
     * Khanza punya ~20 kode `penilaian_awal_medis_ralan_*` (jantung, mata,
     * THT, urologi, psikiatri, dan seterusnya). Di sini seluruhnya dinaungi
     * SATU tabel `clinical.assessments` — dan isian yang membedakan tiap
     * spesialisasi datang dari template berkategori `asesmen-medis`.
     *
     * @return list<array{judul: string, status: string, akibat: string}>
     */
    private function periksaTemplateAsesmen(): array
    {
        $perKategori = FormTemplate::query()
            ->where('is_active', true)
            ->selectRaw('category, count(*) as jumlah')
            ->groupBy('category')
            ->pluck('jumlah', 'category');

        $medis = (int) ($perKategori['asesmen-medis'] ?? 0);
        $skrining = (int) ($perKategori['skrining'] ?? 0);

        $kurang = [];

        if ($medis === 0) {
            $kurang[] = 'asesmen medis per spesialisasi (0 template) — asesmen awal poli '
                .'jantung, mata, THT, dan seterusnya tidak punya isian yang membedakannya';
        }

        if ($skrining === 0) {
            $kurang[] = 'skrining (0 template) — skrining gizi, TB, risiko jatuh, dan '
                .'sekitar tiga puluh instrumen lain tidak bisa diisi';
        }

        if ($kurang === []) {
            return [[
                'judul' => 'Template formulir klinis',
                'status' => self::BERES,
                'akibat' => 'Seluruh kategori punya template.',
            ]];
        }

        return [[
            'judul' => 'Template formulir klinis',
            'status' => self::MENGHALANGI,
            'akibat' => 'Kategori yang belum punya template: '.implode('; ', $kurang).'. '
                .'Mesin formulirnya sudah lengkap (berversi, ada alur persetujuan revisi) '
                .'tapi TIDAK ADA LAYAR untuk menyusun template — seluruh template yang ada '
                .'lahir dari seeder, jadi RSP UI tidak bisa menambah formulir sendiri.',
        ]];
    }
}
