<?php

namespace App\Modules\Order\Database\Seeders;

use App\Modules\Order\Models\TestCatalog;
use Illuminate\Database\Seeder;

/**
 * Contoh pemeriksaan lab dan radiologi untuk pengembangan.
 *
 * Bukan daftar tarif RSP UI yang sebenarnya. Katalog nyata perlu ditinjau
 * ulang berikut rentang rujukan yang berlaku di laboratorium RS masing-masing
 * sebelum dipakai melayani pasien.
 */
class TestCatalogSeeder extends Seeder
{
    public function run(): void
    {
        $lab = [
            // kode, nama, spesimen, tipe hasil, satuan, rujukan bawah, rujukan atas, teks rujukan, harga
            ['LAB-HB', 'Hemoglobin', 'Darah', 'kuantitatif', 'g/dL', 12, 16, null, 35000],
            ['LAB-LEU', 'Leukosit', 'Darah', 'kuantitatif', 'ribu/uL', 4, 11, null, 35000],
            ['LAB-TRO', 'Trombosit', 'Darah', 'kuantitatif', 'ribu/uL', 150, 450, null, 35000],
            ['LAB-GDS', 'Glukosa Darah Sewaktu', 'Darah', 'kuantitatif', 'mg/dL', 70, 140, null, 30000],
            ['LAB-CHOL', 'Kolesterol Total', 'Darah', 'kuantitatif', 'mg/dL', 0, 200, null, 45000],
            ['LAB-SGOT', 'SGOT', 'Darah', 'kuantitatif', 'U/L', 0, 40, null, 40000],
            ['LAB-SGPT', 'SGPT', 'Darah', 'kuantitatif', 'U/L', 0, 41, null, 40000],
            ['LAB-CREA', 'Kreatinin', 'Darah', 'kuantitatif', 'mg/dL', 0.6, 1.3, null, 40000],
            ['LAB-UL', 'Urin Lengkap', 'Urin', 'kualitatif', null, null, null, 'Normal', 50000],
            ['LAB-HBSAG', 'HBsAg', 'Darah', 'kualitatif', null, null, null, 'Negatif', 60000],
        ];

        $radiologi = [
            ['RAD-THX', 'Rontgen Thorax PA', null, 'X-Ray', 'naratif', null, null, null, null, 150000],
            ['RAD-ABD', 'USG Abdomen', null, 'USG', 'naratif', null, null, null, null, 250000],
            ['RAD-KEP', 'CT Scan Kepala Tanpa Kontras', null, 'CT-Scan', 'naratif', null, null, null, null, 850000],
            ['RAD-EXT', 'Rontgen Ekstremitas', null, 'X-Ray', 'naratif', null, null, null, null, 150000],
        ];

        foreach ($lab as [$kode, $nama, $spesimen, $tipe, $satuan, $rendah, $tinggi, $teksRujukan, $harga]) {
            TestCatalog::query()->updateOrCreate(['code' => $kode], [
                'name' => $nama, 'category' => TestCatalog::CATEGORY_LAB,
                'specimen_type' => $spesimen, 'result_type' => $tipe, 'unit' => $satuan,
                'reference_low' => $rendah, 'reference_high' => $tinggi, 'reference_text' => $teksRujukan,
                'price' => $harga, 'is_active' => true,
            ]);
        }

        foreach ($radiologi as [$kode, $nama, $spesimen, $modalitas, $tipe, $rendah, $tinggi, $satuan, $teksRujukan, $harga]) {
            TestCatalog::query()->updateOrCreate(['code' => $kode], [
                'name' => $nama, 'category' => TestCatalog::CATEGORY_RADIOLOGI,
                'modality' => $modalitas, 'result_type' => $tipe, 'unit' => $satuan,
                'reference_low' => $rendah, 'reference_high' => $tinggi, 'reference_text' => $teksRujukan,
                'price' => $harga, 'is_active' => true,
            ]);
        }

        $total = count($lab) + count($radiologi);
        $this->command?->info("Katalog penunjang: {$total} pemeriksaan lab/radiologi contoh dimuat.");
        $this->command?->warn('Tarif dan rentang rujukan harus ditinjau ulang sebelum dipakai melayani pasien.');
    }
}
