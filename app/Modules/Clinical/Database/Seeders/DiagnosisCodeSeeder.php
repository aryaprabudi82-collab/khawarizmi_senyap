<?php

namespace App\Modules\Clinical\Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Contoh kode ICD-10 untuk pengembangan.
 *
 * Bukan kamus lengkap. Kamus resmi diimpor terpisah dari rilis WHO/Kemenkes;
 * yang di sini hanya secukupnya agar layar pemeriksaan bisa dijalankan dan
 * diuji. Diagnosis yang sering muncul di poliklinik rawat jalan.
 */
class DiagnosisCodeSeeder extends Seeder
{
    public function run(): void
    {
        $kode = [
            ['A09', 'Infectious gastroenteritis and colitis, unspecified', 'Gastroenteritis infeksi', 'Penyakit infeksi', 'menular'],
            ['E11.9', 'Type 2 diabetes mellitus without complications', 'Diabetes melitus tipe 2 tanpa komplikasi', 'Endokrin', 'tidak-menular'],
            ['I10', 'Essential (primary) hypertension', 'Hipertensi esensial (primer)', 'Kardiovaskular', 'tidak-menular'],
            ['I50.9', 'Heart failure, unspecified', 'Gagal jantung', 'Kardiovaskular', 'tidak-menular'],
            ['J00', 'Acute nasopharyngitis (common cold)', 'Nasofaringitis akut (pilek)', 'Pernapasan', 'menular'],
            ['J06.9', 'Acute upper respiratory infection, unspecified', 'ISPA akut', 'Pernapasan', 'menular'],
            ['J18.9', 'Pneumonia, unspecified organism', 'Pneumonia', 'Pernapasan', 'menular'],
            ['J45.9', 'Asthma, unspecified', 'Asma', 'Pernapasan', 'tidak-menular'],
            ['K29.7', 'Gastritis, unspecified', 'Gastritis', 'Pencernaan', 'tidak-menular'],
            ['K30', 'Functional dyspepsia', 'Dispepsia fungsional', 'Pencernaan', 'tidak-menular'],
            ['M54.5', 'Low back pain', 'Nyeri punggung bawah', 'Muskuloskeletal', 'tidak-menular'],
            ['N39.0', 'Urinary tract infection, site not specified', 'Infeksi saluran kemih', 'Genitourinaria', 'menular'],
            ['R50.9', 'Fever, unspecified', 'Demam', 'Gejala dan tanda', 'tidak-diketahui'],
            ['R51', 'Headache', 'Nyeri kepala', 'Gejala dan tanda', 'tidak-diketahui'],
            ['Z00.0', 'General adult medical examination', 'Pemeriksaan kesehatan umum', 'Faktor status kesehatan', 'tidak-menular'],
            ['Z34.9', 'Supervision of normal pregnancy, unspecified', 'Pengawasan kehamilan normal', 'Faktor status kesehatan', 'tidak-menular'],
        ];

        $sekarang = now();

        DB::table('clinical.diagnosis_codes')->upsert(
            array_map(fn (array $k): array => [
                'code' => $k[0],
                'display' => $k[1],
                'display_id' => $k[2],
                'chapter' => $k[3],
                'transmission' => $k[4],
                'is_active' => true,
                'created_at' => $sekarang,
                'updated_at' => $sekarang,
            ], $kode),
            ['code'],
            ['display', 'display_id', 'chapter', 'transmission', 'is_active', 'updated_at']
        );

        /*
         * Keanggotaan program surveilans. Sengaja hanya diisi untuk kode
         * yang memang benar anggotanya — bukan ditebak menyeluruh. Daftar
         * PD3I dan TB yang lengkap ditetapkan Kemenkes dan harus diimpor
         * bersama kamus ICD-10 resmi; sampai itu terjadi, laporannya jujur
         * menunjukkan apa adanya, bukan angka karangan.
         *
         * Dari 16 kode contoh, tidak satu pun termasuk PD3I (campak,
         * difteri, pertusis, tetanus, polio, hepatitis B) — jadi daftar ini
         * memang kosong, dan itu keadaan yang benar untuk data contoh.
         */
        $kelompok = [];

        if ($kelompok !== []) {
            DB::table('clinical.diagnosis_surveillance_groups')->upsert(
                $kelompok, ['code', 'group'], ['note', 'updated_at']
            );
        }

        $belumDiklasifikasi = collect($kode)->where(4, 'tidak-diketahui')->count();

        $this->command?->info('Kode diagnosis: ' . count($kode) . ' kode ICD-10 contoh dimuat.');

        if ($belumDiklasifikasi > 0) {
            $this->command?->warn($belumDiklasifikasi . ' kode belum berketerangan menular/tidak — laporan surveilans akan menandainya "tidak diketahui", bukan menebaknya.');
        }
        $this->command?->warn('Kamus ICD-10 resmi harus diimpor terpisah sebelum dipakai melayani pasien.');
    }
}
