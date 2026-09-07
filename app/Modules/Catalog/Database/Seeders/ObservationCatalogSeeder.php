<?php

namespace App\Modules\Catalog\Database\Seeders;

use App\Modules\Catalog\Models\ObservationCode;
use App\Modules\Catalog\Models\ObservationPanel;
use Illuminate\Database\Seeder;

/**
 * Katalog pengukuran & panel observasi (domain M item D).
 *
 * ISINYA MEMINDAHKAN konstanta Observation::CATALOG yang selama ini
 * tertanam di kode, lalu menambahkan pengukuran yang dibutuhkan panel
 * bangsal dan unit khusus. Nilai kesembilan pengukuran lama disalin PERSIS
 * supaya perilaku yang sudah diuji tidak berubah diam-diam.
 *
 * PANELNYA MENGIKUTI KHANZA, bukan dikarang: setiap catatan_observasi_*
 * di sana jadi satu panel di sini, dengan pengukuran yang sama seperti
 * kolom tabelnya.
 *
 * RENTANG PANEL NEONATUS DAN BAYI SENGAJA BERBEDA dari dewasa. Laju napas
 * 40 kali per menit normal pada neonatus dan gawat pada dewasa; memakai
 * rentang dewasa untuk bayi akan menyalakan penanda abnormal sepanjang
 * hari, dan penanda yang selalu menyala melatih orang mengabaikannya.
 * Angka di bawah mengikuti rentang yang lazim dipakai; RSP UI perlu
 * meninjaunya bersama komite medik sebelum dipakai melayani pasien.
 */
class ObservationCatalogSeeder extends Seeder
{
    public function run(): void
    {
        $this->seedCodes();
        $this->seedPanels();

        $this->command?->info('Katalog observasi: ' . ObservationCode::query()->count()
            . ' pengukuran, ' . ObservationPanel::query()->count() . ' panel.');
        $this->command?->warn('Rentang rujukan contoh — tinjau bersama komite medik sebelum dipakai melayani pasien.');
    }

    private function seedCodes(): void
    {
        // kode, tampilan, satuan, tipe, rentang bawah, rentang atas, kategori
        $kode = [
            // Sembilan pengukuran lama, disalin persis dari konstanta.
            ['tekanan-darah-sistolik', 'Tekanan darah sistolik', 'mmHg', 'numeric', 90, 140, 'tanda-vital'],
            ['tekanan-darah-diastolik', 'Tekanan darah diastolik', 'mmHg', 'numeric', 60, 90, 'tanda-vital'],
            ['nadi', 'Denyut nadi', 'x/menit', 'numeric', 60, 100, 'tanda-vital'],
            ['laju-napas', 'Laju napas', 'x/menit', 'numeric', 12, 20, 'tanda-vital'],
            ['suhu', 'Suhu tubuh', '°C', 'numeric', 36.0, 37.5, 'tanda-vital'],
            ['saturasi-oksigen', 'Saturasi oksigen', '%', 'numeric', 95, 100, 'tanda-vital'],
            ['berat-badan', 'Berat badan', 'kg', 'numeric', null, null, 'antropometri'],
            ['tinggi-badan', 'Tinggi badan', 'cm', 'numeric', null, null, 'antropometri'],
            ['skala-nyeri', 'Skala nyeri', '0-10', 'numeric', 0, 3, 'tanda-vital'],

            // Tambahan yang dibutuhkan panel bangsal dan unit khusus.
            ['gcs', 'Glasgow Coma Scale', '3-15', 'numeric', 15, 15, 'neurologis'],
            ['kesadaran', 'Tingkat kesadaran', null, 'text', null, null, 'neurologis'],
            ['gula-darah-sewaktu', 'Gula darah sewaktu', 'mg/dL', 'numeric', 70, 140, 'gula-darah'],
            ['insulin-diberikan', 'Insulin diberikan', null, 'text', null, null, 'gula-darah'],

            // Ventilator — mengikuti kolom catatan_observasi_ventilator.
            ['ventilator-mode', 'Mode ventilator', null, 'text', null, null, 'ventilator'],
            ['ventilator-volume-tidal', 'Volume tidal', 'mL', 'numeric', null, null, 'ventilator'],
            ['ventilator-peep', 'PEEP', 'cmH2O', 'numeric', null, null, 'ventilator'],
            ['ventilator-laju-napas', 'Laju napas ventilator', 'x/menit', 'numeric', null, null, 'ventilator'],
            ['ventilator-fio2', 'FiO2', '%', 'numeric', null, null, 'ventilator'],

            // Kebidanan.
            ['denyut-jantung-janin', 'Denyut jantung janin', 'x/menit', 'numeric', 120, 160, 'kebidanan'],
            ['his', 'Kontraksi (his)', 'x/10 menit', 'numeric', null, null, 'kebidanan'],
            ['pembukaan-serviks', 'Pembukaan serviks', 'cm', 'numeric', null, null, 'kebidanan'],
            ['tinggi-fundus-uteri', 'Tinggi fundus uteri', 'cm', 'numeric', null, null, 'kebidanan'],
            ['perdarahan', 'Perdarahan', 'mL', 'numeric', null, null, 'kebidanan'],
        ];

        foreach ($kode as [$c, $nama, $satuan, $tipe, $bawah, $atas, $kategori]) {
            ObservationCode::query()->updateOrCreate(['code' => $c], [
                'display' => $nama,
                'unit' => $satuan,
                'value_type' => $tipe,
                'reference_low' => $bawah,
                'reference_high' => $atas,
                'category' => $kategori,
                'is_active' => true,
            ]);
        }
    }

    private function seedPanels(): void
    {
        $vitalDewasa = ['gcs', 'tekanan-darah-sistolik', 'tekanan-darah-diastolik', 'nadi', 'laju-napas', 'suhu', 'saturasi-oksigen'];

        $panel = [
            // kode, nama, konteks, kelompok umur, pengukuran, rentang khusus
            ['observasi-ranap', 'Catatan Observasi Ranap', 'ranap', 'dewasa', $vitalDewasa, []],
            ['observasi-igd', 'Catatan Observasi IGD', 'igd', 'dewasa', $vitalDewasa, []],
            ['observasi-ruang-ok', 'Catatan Observasi Ruang Operasi', 'ok', 'dewasa', $vitalDewasa, []],
            ['observasi-chbp', 'Catatan Observasi CHBP', 'ranap', 'dewasa', $vitalDewasa, []],
            ['observasi-restrain', 'Catatan Observasi Restrain Nonfarmakologi', 'ranap', 'dewasa',
                ['tekanan-darah-sistolik', 'nadi', 'laju-napas', 'kesadaran'], []],

            // Bayi: rentang laju napas dan nadi memang berbeda — lihat
            // catatan kelas.
            ['observasi-bayi', 'Catatan Observasi Bayi', 'ranap', 'bayi',
                ['nadi', 'laju-napas', 'suhu', 'saturasi-oksigen', 'berat-badan'],
                ['laju-napas' => [30, 60], 'nadi' => [100, 160]]],

            ['observasi-hemodialisa', 'Catatan Observasi Hemodialisa', 'hemodialisa', 'dewasa',
                ['tekanan-darah-sistolik', 'tekanan-darah-diastolik', 'nadi', 'suhu', 'berat-badan'], []],

            ['observasi-ventilator', 'Catatan Observasi Ventilator', 'ventilator', null,
                ['ventilator-mode', 'ventilator-volume-tidal', 'ventilator-peep', 'ventilator-laju-napas', 'ventilator-fio2', 'saturasi-oksigen'], []],

            ['observasi-kebidanan', 'Catatan Observasi Ranap Kebidanan', 'kebidanan', 'dewasa',
                ['tekanan-darah-sistolik', 'tekanan-darah-diastolik', 'nadi', 'suhu', 'denyut-jantung-janin', 'his', 'pembukaan-serviks'], []],

            ['observasi-postpartum', 'Catatan Observasi Ranap Post Partum', 'kebidanan', 'dewasa',
                ['tekanan-darah-sistolik', 'tekanan-darah-diastolik', 'nadi', 'suhu', 'tinggi-fundus-uteri', 'perdarahan'], []],

            ['observasi-induksi-persalinan', 'Catatan Observasi Induksi Persalinan', 'kebidanan', 'dewasa',
                ['tekanan-darah-sistolik', 'nadi', 'denyut-jantung-janin', 'his', 'pembukaan-serviks'], []],

            ['cek-gds', 'Catatan Cek GDS', 'ranap', null,
                ['gula-darah-sewaktu', 'insulin-diberikan'], []],
        ];

        foreach ($panel as [$kode, $nama, $konteks, $umur, $pengukuran, $rentang]) {
            $p = ObservationPanel::query()->updateOrCreate(['code' => $kode], [
                'name' => $nama,
                'care_context' => $konteks,
                'age_group' => $umur,
                'is_active' => true,
            ]);

            foreach (array_values($pengukuran) as $i => $c) {
                $kodeObs = ObservationCode::query()->where('code', $c)->first();

                if ($kodeObs === null) {
                    continue;
                }

                $p->items()->updateOrCreate(
                    ['observation_code_id' => $kodeObs->id],
                    [
                        'sequence' => $i + 1,
                        'is_required' => false,
                        'reference_low' => $rentang[$c][0] ?? null,
                        'reference_high' => $rentang[$c][1] ?? null,
                    ]
                );
            }
        }
    }
}
