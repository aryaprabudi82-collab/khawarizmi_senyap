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
            ['A09', 'Infectious gastroenteritis and colitis, unspecified', 'Gastroenteritis infeksi', 'Penyakit infeksi'],
            ['E11.9', 'Type 2 diabetes mellitus without complications', 'Diabetes melitus tipe 2 tanpa komplikasi', 'Endokrin'],
            ['I10', 'Essential (primary) hypertension', 'Hipertensi esensial (primer)', 'Kardiovaskular'],
            ['I50.9', 'Heart failure, unspecified', 'Gagal jantung', 'Kardiovaskular'],
            ['J00', 'Acute nasopharyngitis (common cold)', 'Nasofaringitis akut (pilek)', 'Pernapasan'],
            ['J06.9', 'Acute upper respiratory infection, unspecified', 'ISPA akut', 'Pernapasan'],
            ['J18.9', 'Pneumonia, unspecified organism', 'Pneumonia', 'Pernapasan'],
            ['J45.9', 'Asthma, unspecified', 'Asma', 'Pernapasan'],
            ['K29.7', 'Gastritis, unspecified', 'Gastritis', 'Pencernaan'],
            ['K30', 'Functional dyspepsia', 'Dispepsia fungsional', 'Pencernaan'],
            ['M54.5', 'Low back pain', 'Nyeri punggung bawah', 'Muskuloskeletal'],
            ['N39.0', 'Urinary tract infection, site not specified', 'Infeksi saluran kemih', 'Genitourinaria'],
            ['R50.9', 'Fever, unspecified', 'Demam', 'Gejala dan tanda'],
            ['R51', 'Headache', 'Nyeri kepala', 'Gejala dan tanda'],
            ['Z00.0', 'General adult medical examination', 'Pemeriksaan kesehatan umum', 'Faktor status kesehatan'],
            ['Z34.9', 'Supervision of normal pregnancy, unspecified', 'Pengawasan kehamilan normal', 'Faktor status kesehatan'],
        ];

        $sekarang = now();

        DB::table('clinical.diagnosis_codes')->upsert(
            array_map(fn (array $k): array => [
                'code' => $k[0],
                'display' => $k[1],
                'display_id' => $k[2],
                'chapter' => $k[3],
                'is_active' => true,
                'created_at' => $sekarang,
                'updated_at' => $sekarang,
            ], $kode),
            ['code'],
            ['display', 'display_id', 'chapter', 'is_active', 'updated_at']
        );

        $this->command?->info('Kode diagnosis: ' . count($kode) . ' kode ICD-10 contoh dimuat.');
        $this->command?->warn('Kamus ICD-10 resmi harus diimpor terpisah sebelum dipakai melayani pasien.');
    }
}
