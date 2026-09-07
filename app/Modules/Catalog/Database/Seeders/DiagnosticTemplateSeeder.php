<?php

namespace App\Modules\Catalog\Database\Seeders;

use App\Modules\Catalog\Models\FormTemplate;
use Illuminate\Database\Seeder;

/**
 * Template hasil pemeriksaan penunjang khusus (domain M item G).
 *
 * BUTIRNYA DIAMBIL DARI KOLOM TABEL KHANZA, bukan dikarang. EKG:
 * hasil_pemeriksaan_ekg punya irama, laju jantung, gelombang P, interval
 * PR, aksis, kompleks QRS, segmen ST, gelombang T. USG kandungan:
 * hasil_pemeriksaan_usg punya kantong gestasi, ukuran bokong-kepala,
 * diameter biparietal, panjang femur, lingkar abdomen, tafsiran berat
 * janin, usia kehamilan. Yang disemai di sini persis butir-butir itu.
 *
 * TIDAK ADA YANG DISKOR. Hasil pemeriksaan penunjang bukan instrumen
 * bernilai angka — yang menyimpulkan adalah pemeriksanya, dan menaruh
 * ambang skor di sini akan membuat sistem seolah menafsirkan EKG sendiri.
 * Karena itu scoring dibiarkan kosong dan kesimpulan diisi manusia.
 *
 * SELURUHNYA MASUK BELUM DISAHKAN, aturan yang sama seperti item F:
 * butirnya boleh setia pada Khanza, tapi mengadopsinya tetap keputusan
 * RSP UI.
 */
class DiagnosticTemplateSeeder extends Seeder
{
    public function run(): void
    {
        foreach ($this->templates() as $t) {
            if (FormTemplate::query()->where('code', $t['code'])->exists()) {
                continue;
            }

            FormTemplate::query()->create([
                'code' => $t['code'],
                'version' => 1,
                'name' => $t['name'],
                'category' => FormTemplate::HASIL_PEMERIKSAAN,
                'specialty' => $t['modality'],
                'sections' => $t['sections'],
                'scoring' => null,
                'is_repeatable' => true,
                'note' => $t['note'],
                'is_approved' => false,
                'is_active' => true,
            ]);
        }

        $jumlah = FormTemplate::query()->where('category', FormTemplate::HASIL_PEMERIKSAAN)->count();

        $this->command?->info("Template hasil pemeriksaan: {$jumlah} modalitas.");
        $this->command?->warn('Belum disahkan — komite medik perlu meninjau butirnya sebelum dipakai.');
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function templates(): array
    {
        return [
            [
                'code' => 'hasil-ekg',
                'name' => 'Hasil Pemeriksaan EKG',
                'modality' => 'ekg',
                'note' => 'Butir mengikuti kolom hasil_pemeriksaan_ekg Khanza. '
                    . 'Perlu ditinjau komite medik sebelum disahkan.',
                'sections' => [[
                    'title' => 'Pembacaan EKG',
                    'questions' => [
                        $this->teks('irama', 'Irama', true),
                        $this->teks('laju-jantung', 'Laju jantung (x/menit)', true),
                        $this->teks('gelombang-p', 'Gelombang P'),
                        $this->teks('interval-pr', 'Interval PR'),
                        $this->teks('aksis', 'Aksis'),
                        $this->teks('kompleks-qrs', 'Kompleks QRS'),
                        $this->pilihan('segmen-st', 'Segmen ST', ['normal' => 'Normal', 'tidak-normal' => 'Tidak normal']),
                        $this->pilihan('gelombang-t', 'Gelombang T', ['normal' => 'Normal', 'tidak-normal' => 'Tidak normal']),
                    ],
                ]],
            ],
            [
                'code' => 'hasil-usg-kandungan',
                'name' => 'Hasil USG Kandungan',
                'modality' => 'usg',
                'note' => 'Butir mengikuti kolom hasil_pemeriksaan_usg Khanza. '
                    . 'Perlu ditinjau komite medik sebelum disahkan.',
                'sections' => [[
                    'title' => 'Biometri janin',
                    'questions' => [
                        $this->teks('kantong-gestasi', 'Kantong gestasi (mm)'),
                        $this->teks('ukuran-bokong-kepala', 'Ukuran bokong-kepala / CRL (mm)'),
                        $this->teks('diameter-biparietal', 'Diameter biparietal (mm)'),
                        $this->teks('panjang-femur', 'Panjang femur (mm)'),
                        $this->teks('lingkar-abdomen', 'Lingkar abdomen (mm)'),
                        $this->teks('tafsiran-berat-janin', 'Tafsiran berat janin (gram)'),
                        $this->teks('usia-kehamilan', 'Usia kehamilan', true),
                        $this->teks('presentasi', 'Presentasi janin'),
                        $this->teks('denyut-jantung-janin', 'Denyut jantung janin (x/menit)'),
                    ],
                ]],
            ],
            [
                'code' => 'hasil-usg-abdomen',
                'name' => 'Hasil USG Abdomen',
                'modality' => 'usg',
                'note' => 'Butir mengikuti organ yang lazim dinilai pada USG abdomen. '
                    . 'Perlu ditinjau komite medik sebelum disahkan.',
                'sections' => [[
                    'title' => 'Organ intraabdomen',
                    'questions' => [
                        $this->teks('hepar', 'Hepar'),
                        $this->teks('kandung-empedu', 'Kandung empedu'),
                        $this->teks('pankreas', 'Pankreas'),
                        $this->teks('lien', 'Lien'),
                        $this->teks('ginjal-kanan', 'Ginjal kanan'),
                        $this->teks('ginjal-kiri', 'Ginjal kiri'),
                        $this->teks('kandung-kemih', 'Kandung kemih'),
                        $this->teks('cairan-bebas', 'Cairan bebas'),
                    ],
                ]],
            ],
            [
                'code' => 'hasil-echo',
                'name' => 'Hasil Pemeriksaan Ekokardiografi',
                'modality' => 'echo',
                'note' => 'Butir mengikuti pengukuran yang lazim dilaporkan pada ekokardiografi. '
                    . 'Perlu ditinjau komite medik sebelum disahkan.',
                'sections' => [[
                    'title' => 'Dimensi & fungsi',
                    'questions' => [
                        $this->teks('dimensi-ventrikel-kiri', 'Dimensi ventrikel kiri'),
                        $this->teks('fraksi-ejeksi', 'Fraksi ejeksi (%)', true),
                        $this->teks('katup-mitral', 'Katup mitral'),
                        $this->teks('katup-aorta', 'Katup aorta'),
                        $this->teks('katup-trikuspid', 'Katup trikuspid'),
                        $this->teks('perikardium', 'Perikardium'),
                        $this->teks('kinetik-dinding', 'Kinetik dinding'),
                    ],
                ]],
            ],
            [
                'code' => 'hasil-treadmill',
                'name' => 'Hasil Uji Latih Jantung (Treadmill)',
                'modality' => 'treadmill',
                'note' => 'Butir mengikuti pelaporan uji latih jantung yang lazim. '
                    . 'Perlu ditinjau komite medik sebelum disahkan.',
                'sections' => [[
                    'title' => 'Uji latih',
                    'questions' => [
                        $this->teks('protokol', 'Protokol', true),
                        $this->teks('lama-latihan', 'Lama latihan'),
                        $this->teks('laju-jantung-maksimal', 'Laju jantung maksimal tercapai'),
                        $this->teks('tekanan-darah-puncak', 'Tekanan darah puncak'),
                        $this->teks('alasan-berhenti', 'Alasan penghentian', true),
                        $this->pilihan('perubahan-segmen-st', 'Perubahan segmen ST', [
                            'tidak-ada' => 'Tidak ada', 'depresi' => 'Depresi', 'elevasi' => 'Elevasi',
                        ]),
                        $this->pilihan('keluhan', 'Keluhan selama uji', [
                            'tidak-ada' => 'Tidak ada', 'nyeri-dada' => 'Nyeri dada', 'sesak' => 'Sesak', 'lain' => 'Lain-lain',
                        ]),
                    ],
                ]],
            ],
            [
                'code' => 'hasil-endoskopi-tht',
                'name' => 'Hasil Endoskopi THT',
                'modality' => 'endoskopi',
                'note' => 'Menaungi endoskopi telinga, hidung, dan faring/laring — ketiganya '
                    . 'satu pemeriksaan dengan bagian yang dinilai berbeda. '
                    . 'Perlu ditinjau komite medik sebelum disahkan.',
                'sections' => [[
                    'title' => 'Temuan endoskopi',
                    'questions' => [
                        $this->pilihan('bagian', 'Bagian yang diperiksa', [
                            'telinga' => 'Telinga', 'hidung' => 'Hidung', 'faring-laring' => 'Faring / laring',
                        ], true),
                        $this->teks('temuan-kanan', 'Temuan sisi kanan'),
                        $this->teks('temuan-kiri', 'Temuan sisi kiri'),
                        $this->teks('mukosa', 'Mukosa'),
                        $this->teks('sekret', 'Sekret'),
                        $this->teks('massa', 'Massa atau kelainan lain'),
                    ],
                ]],
            ],
            [
                'code' => 'hasil-slit-lamp',
                'name' => 'Hasil Pemeriksaan Slit Lamp',
                'modality' => 'mata',
                'note' => 'Butir mengikuti segmen anterior yang lazim dinilai. '
                    . 'Perlu ditinjau komite medik sebelum disahkan.',
                'sections' => [[
                    'title' => 'Segmen anterior',
                    'questions' => [
                        $this->teks('palpebra', 'Palpebra'),
                        $this->teks('konjungtiva', 'Konjungtiva'),
                        $this->teks('kornea', 'Kornea'),
                        $this->teks('bilik-mata-depan', 'Bilik mata depan'),
                        $this->teks('iris', 'Iris'),
                        $this->teks('pupil', 'Pupil'),
                        $this->teks('lensa', 'Lensa'),
                    ],
                ]],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function teks(string $key, string $label, bool $wajib = false): array
    {
        return ['key' => $key, 'label' => $label, 'type' => 'text', 'required' => $wajib];
    }

    /**
     * @param  array<string, string>  $pilihan
     * @return array<string, mixed>
     */
    private function pilihan(string $key, string $label, array $pilihan, bool $wajib = false): array
    {
        return [
            'key' => $key,
            'label' => $label,
            'type' => 'choice',
            'required' => $wajib,
            'options' => array_map(
                fn ($nilai, $teks) => ['value' => $nilai, 'label' => $teks],
                array_keys($pilihan),
                array_values($pilihan),
            ),
        ];
    }
}
