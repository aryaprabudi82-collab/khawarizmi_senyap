<?php

namespace App\Modules\Catalog\Database\Seeders;

use App\Modules\Catalog\Models\DisabilityType;
use App\Modules\Catalog\Models\ImmunisationType;
use Illuminate\Database\Seeder;

/**
 * Master imunisasi & ragam disabilitas (domain M item S).
 *
 * KEDUANYA DISEMAI KARENA ISINYA MEMANG DITETAPKAN DI LUAR RUMAH SAKIT,
 * bukan dikarang — aturan yang sama seperti instrumen baku pada item F.
 *
 * Vaksin program imunisasi nasional ditetapkan Kemenkes, dan jumlah
 * dosis serta jarak antar dosisnya bagian dari jadwal resmi itu. Ragam
 * penyandang disabilitas mengikuti pengelompokan UU 8/2016: fisik,
 * intelektual, mental, sensorik, dan ganda.
 *
 * Yang TIDAK disemai: vaksin di luar program nasional yang pengadaannya
 * keputusan rumah sakit, dan jenis cacat yang lebih rinci daripada
 * ragam undang-undangnya. Keduanya ditambahkan pengelola master saat
 * dibutuhkan.
 */
class ImmunisationDisabilitySeeder extends Seeder
{
    public function run(): void
    {
        foreach ($this->immunisations() as $jenis) {
            ImmunisationType::query()->updateOrCreate(['code' => $jenis['code']], $jenis);
        }

        foreach ($this->disabilities() as $jenis) {
            DisabilityType::query()->updateOrCreate(['code' => $jenis['code']], $jenis);
        }

        $this->command?->info(sprintf(
            'Master imunisasi: %d jenis; ragam disabilitas: %d jenis.',
            ImmunisationType::query()->count(),
            DisabilityType::query()->count(),
        ));
    }

    /**
     * Vaksin program imunisasi nasional.
     *
     * Jumlah dosis dan jaraknya mengikuti jadwal imunisasi nasional.
     * Vaksin yang jadwalnya bergantung usia dan bukan sekadar jarak
     * antar dosis dibiarkan interval_days kosong — sistem tidak akan
     * mengarang jadwal yang sebenarnya ditentukan umur anak.
     *
     * @return array<int, array<string, mixed>>
     */
    private function immunisations(): array
    {
        return [
            [
                'code' => 'HB0', 'name' => 'Hepatitis B (HB-0)',
                'disease_prevented' => 'Hepatitis B',
                'total_doses' => 1, 'interval_days' => null,
                'route' => 'intramuskular', 'is_national_programme' => true,
                'note' => 'Diberikan dalam 24 jam pertama setelah lahir.',
            ],
            [
                'code' => 'BCG', 'name' => 'BCG',
                'disease_prevented' => 'Tuberkulosis berat',
                'total_doses' => 1, 'interval_days' => null,
                'route' => 'intradermal', 'is_national_programme' => true,
            ],
            [
                'code' => 'POLIO-TETES', 'name' => 'Polio tetes (OPV)',
                'disease_prevented' => 'Poliomielitis',
                'total_doses' => 4, 'interval_days' => 28,
                'route' => 'oral', 'is_national_programme' => true,
            ],
            [
                'code' => 'POLIO-SUNTIK', 'name' => 'Polio suntik (IPV)',
                'disease_prevented' => 'Poliomielitis',
                'total_doses' => 2, 'interval_days' => 120,
                'route' => 'intramuskular', 'is_national_programme' => true,
            ],
            [
                'code' => 'DPT-HB-HIB', 'name' => 'DPT-HB-Hib',
                'disease_prevented' => 'Difteri, pertusis, tetanus, hepatitis B, Haemophilus influenzae tipe b',
                'total_doses' => 4, 'interval_days' => 28,
                'route' => 'intramuskular', 'is_national_programme' => true,
                'note' => 'Tiga dosis dasar ditambah satu dosis lanjutan.',
            ],
            [
                'code' => 'CAMPAK-RUBELA', 'name' => 'Campak-Rubela (MR)',
                'disease_prevented' => 'Campak dan rubela',
                'total_doses' => 3, 'interval_days' => null,
                'route' => 'subkutan', 'is_national_programme' => true,
                'note' => 'Jadwalnya ditentukan umur anak, bukan jarak tetap antar dosis.',
            ],
            [
                'code' => 'PCV', 'name' => 'PCV',
                'disease_prevented' => 'Pneumonia dan meningitis pneumokokus',
                'total_doses' => 3, 'interval_days' => 28,
                'route' => 'intramuskular', 'is_national_programme' => true,
            ],
            [
                'code' => 'ROTAVIRUS', 'name' => 'Rotavirus (RV)',
                'disease_prevented' => 'Diare rotavirus',
                'total_doses' => 3, 'interval_days' => 28,
                'route' => 'oral', 'is_national_programme' => true,
            ],
            [
                'code' => 'HPV', 'name' => 'HPV',
                'disease_prevented' => 'Kanker serviks',
                'total_doses' => 2, 'interval_days' => 365,
                'route' => 'intramuskular', 'is_national_programme' => true,
            ],
            [
                'code' => 'TD', 'name' => 'Td (tetanus-difteri)',
                'disease_prevented' => 'Tetanus dan difteri',
                'total_doses' => null, 'interval_days' => null,
                'route' => 'intramuskular', 'is_national_programme' => true,
                'note' => 'Diulang sesuai status imunisasi tetanus; tidak berbatas dosis tetap.',
            ],
        ];
    }

    /**
     * Ragam penyandang disabilitas menurut UU 8/2016.
     *
     * @return array<int, array<string, mixed>>
     */
    private function disabilities(): array
    {
        return [
            ['code' => 'FISIK-GERAK', 'name' => 'Gangguan gerak anggota tubuh', 'category' => 'fisik'],
            ['code' => 'FISIK-AMPUTASI', 'name' => 'Amputasi anggota gerak', 'category' => 'fisik'],
            ['code' => 'FISIK-LUMPUH', 'name' => 'Kelumpuhan', 'category' => 'fisik'],
            ['code' => 'SENSORIK-LIHAT', 'name' => 'Gangguan penglihatan', 'category' => 'sensorik'],
            ['code' => 'SENSORIK-DENGAR', 'name' => 'Gangguan pendengaran', 'category' => 'sensorik'],
            ['code' => 'SENSORIK-WICARA', 'name' => 'Gangguan wicara', 'category' => 'sensorik'],
            ['code' => 'INTELEKTUAL', 'name' => 'Disabilitas intelektual', 'category' => 'intelektual'],
            ['code' => 'MENTAL', 'name' => 'Disabilitas mental', 'category' => 'mental'],
            ['code' => 'GANDA', 'name' => 'Disabilitas ganda', 'category' => 'ganda',
                'note' => 'Dua ragam atau lebih sekaligus; ragam penyertanya dicatat terpisah.'],
        ];
    }
}
