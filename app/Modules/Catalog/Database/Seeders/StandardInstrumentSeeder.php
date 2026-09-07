<?php

namespace App\Modules\Catalog\Database\Seeders;

use App\Modules\Catalog\Models\FormTemplate;
use Illuminate\Database\Seeder;

/**
 * Instrumen skrining & pengkajian BAKU (domain M item F).
 *
 * ================== BATAS YANG DIPEGANG SEEDER INI ==================
 *
 * Yang disemai di sini HANYA instrumen yang isinya sudah tertentu karena
 * memang terbit sebagai instrumen baku: Morse Fall Scale, Humpty Dumpty,
 * Braden, Aldrete, Bromage, Steward, dan Early Warning Score. Butir dan
 * bobotnya mengikuti instrumen aslinya, bukan dikarang.
 *
 * YANG TIDAK DISEMAI DI SINI adalah formulir yang isinya keputusan komite
 * medik RSP UI — "Awal Medis Ralan Mata", "Pengkajian Terapi Wicara",
 * kriteria masuk ICU, dan sejenisnya. Isinya berbeda antar rumah sakit
 * karena memang ditetapkan masing-masing, dan mengarangnya akan
 * menghasilkan formulir yang tampak resmi tapi tidak pernah disepakati
 * siapa pun — lalu isinya masuk ke rekam medis pasien dan dibaca sebagai
 * standar rumah sakit. Mekanisme template dari item A justru dibangun
 * supaya komite bisa menyusunnya sendiri tanpa menyentuh kode.
 *
 * SELURUHNYA MASUK DENGAN is_approved = false, TERMASUK yang baku.
 * Instrumen bakunya sahih sebagai instrumen; yang belum terjadi adalah
 * RSP UI mengadopsinya. Kesetiaan pada instrumen aslinya tidak
 * menggantikan keputusan rumah sakit untuk memakainya.
 *
 * AMBANG SKOR IKUT DISEMAI karena ia bagian dari instrumennya — Morse
 * tanpa ambang 25 dan 45 bukan Morse. Tapi ambang itu pun perlu ditinjau:
 * beberapa rumah sakit menggesernya mengikuti populasi pasiennya, dan
 * mekanisme versi dari item A memang disiapkan untuk itu.
 */
class StandardInstrumentSeeder extends Seeder
{
    public function run(): void
    {
        foreach ($this->instruments() as $t) {
            $ada = FormTemplate::query()->where('code', $t['code'])->exists();

            if ($ada) {
                continue;
            }

            FormTemplate::query()->create([
                'code' => $t['code'],
                'version' => 1,
                'name' => $t['name'],
                'category' => $t['category'],
                'specialty' => $t['specialty'] ?? null,
                'age_group' => $t['age_group'] ?? null,
                'sections' => $t['sections'],
                'scoring' => $t['scoring'] ?? null,
                'is_repeatable' => $t['is_repeatable'] ?? false,
                'note' => $t['note'],
                // SELALU false — lihat catatan kelas.
                'is_approved' => false,
                'is_active' => true,
            ]);
        }

        $this->command?->info('Instrumen baku: ' . FormTemplate::query()->count() . ' template tersedia.');
        $this->command?->warn(
            'SELURUHNYA BELUM DISAHKAN. Komite medik RSP UI perlu meninjau butir dan ambangnya, '
            . 'lalu mengesahkannya lewat layar template sebelum dipakai melayani pasien.'
        );
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function instruments(): array
    {
        return [
            $this->morse(),
            $this->humptyDumpty(),
            $this->braden(),
            $this->aldrete(),
            $this->bromage(),
            $this->steward(),
            $this->ewsDewasa(),
        ];
    }

    /** Morse Fall Scale — pengkajian lanjutan risiko jatuh dewasa. */
    private function morse(): array
    {
        return [
            'code' => 'risiko-jatuh-dewasa',
            'name' => 'Pengkajian Lanjutan Risiko Jatuh Dewasa (Morse Fall Scale)',
            'category' => FormTemplate::PENGKAJIAN_LANJUTAN,
            'age_group' => 'dewasa',
            'is_repeatable' => true,
            'note' => 'Morse Fall Scale. Bobot dan ambang mengikuti instrumen aslinya; '
                . 'perlu ditinjau komite medik sebelum disahkan.',
            'sections' => [[
                'title' => 'Faktor risiko jatuh',
                'questions' => [
                    $this->pilihan('riwayat-jatuh', 'Riwayat jatuh dalam 3 bulan terakhir', [
                        ['tidak', 'Tidak', 0], ['ya', 'Ya', 25],
                    ], true),
                    $this->pilihan('diagnosis-sekunder', 'Diagnosis sekunder (lebih dari satu diagnosis medis)', [
                        ['tidak', 'Tidak', 0], ['ya', 'Ya', 15],
                    ], true),
                    $this->pilihan('alat-bantu', 'Alat bantu jalan', [
                        ['tidak-ada', 'Tidak ada / bedrest / dibantu perawat', 0],
                        ['tongkat', 'Kruk, tongkat, atau walker', 15],
                        ['perabot', 'Berpegangan pada perabot', 30],
                    ], true),
                    $this->pilihan('infus', 'Terpasang infus atau heparin lock', [
                        ['tidak', 'Tidak', 0], ['ya', 'Ya', 20],
                    ], true),
                    $this->pilihan('gaya-berjalan', 'Gaya berjalan', [
                        ['normal', 'Normal / bedrest / kursi roda', 0],
                        ['lemah', 'Lemah', 10],
                        ['terganggu', 'Terganggu', 20],
                    ], true),
                    $this->pilihan('status-mental', 'Status mental', [
                        ['sadar-batas', 'Sadar akan keterbatasan diri', 0],
                        ['lupa-batas', 'Sering lupa akan keterbatasan diri', 15],
                    ], true),
                ],
            ]],
            'scoring' => ['bands' => [
                ['min' => 0, 'max' => 24, 'risk_level' => 'rendah', 'interpretation' => 'Risiko rendah — perawatan dasar pencegahan jatuh.'],
                ['min' => 25, 'max' => 44, 'risk_level' => 'sedang', 'interpretation' => 'Risiko sedang — terapkan intervensi pencegahan jatuh standar.'],
                ['min' => 45, 'max' => 125, 'risk_level' => 'tinggi', 'interpretation' => 'Risiko tinggi — terapkan intervensi pencegahan jatuh tingkat tinggi.'],
            ]],
        ];
    }

    /** Humpty Dumpty Falls Scale — risiko jatuh anak. */
    private function humptyDumpty(): array
    {
        return [
            'code' => 'risiko-jatuh-anak',
            'name' => 'Pengkajian Lanjutan Risiko Jatuh Anak (Humpty Dumpty)',
            'category' => FormTemplate::PENGKAJIAN_LANJUTAN,
            'age_group' => 'anak',
            'is_repeatable' => true,
            'note' => 'Humpty Dumpty Falls Scale. Bobot dan ambang mengikuti instrumen aslinya; '
                . 'perlu ditinjau komite medik sebelum disahkan.',
            'sections' => [[
                'title' => 'Faktor risiko jatuh anak',
                'questions' => [
                    $this->pilihan('umur', 'Umur', [
                        ['bawah-3', 'Di bawah 3 tahun', 4],
                        ['3-7', '3 sampai 7 tahun', 3],
                        ['7-13', '7 sampai 13 tahun', 2],
                        ['atas-13', '13 tahun ke atas', 1],
                    ], true),
                    $this->pilihan('jenis-kelamin', 'Jenis kelamin', [
                        ['laki-laki', 'Laki-laki', 2], ['perempuan', 'Perempuan', 1],
                    ], true),
                    $this->pilihan('diagnosis', 'Diagnosis', [
                        ['neurologi', 'Kelainan neurologi', 4],
                        ['oksigenasi', 'Gangguan oksigenasi', 3],
                        ['perilaku', 'Gangguan perilaku / psikiatri', 2],
                        ['lain', 'Diagnosis lain', 1],
                    ], true),
                    $this->pilihan('kognitif', 'Gangguan kognitif', [
                        ['tidak-sadar-batas', 'Tidak sadar akan keterbatasan diri', 3],
                        ['lupa-batas', 'Lupa akan keterbatasan diri', 2],
                        ['orientasi-baik', 'Orientasi baik terhadap keterbatasan diri', 1],
                    ], true),
                    $this->pilihan('lingkungan', 'Faktor lingkungan', [
                        ['riwayat-jatuh', 'Riwayat jatuh dari tempat tidur saat bayi/anak', 4],
                        ['alat-bantu', 'Memakai alat bantu / boks / perabot rumah', 3],
                        ['tempat-tidur', 'Pasien berada di tempat tidur', 2],
                        ['rawat-jalan', 'Area rawat jalan', 1],
                    ], true),
                    $this->pilihan('pembedahan', 'Respons terhadap pembedahan, sedasi, atau anestesi', [
                        ['24jam', 'Dalam 24 jam', 3],
                        ['48jam', 'Dalam 48 jam', 2],
                        ['lebih-48jam', 'Lebih dari 48 jam atau tidak menjalani', 1],
                    ], true),
                    $this->pilihan('obat', 'Penggunaan obat', [
                        ['bermacam', 'Bermacam obat (sedatif, hipnotik, barbiturat, dan lain-lain)', 3],
                        ['salah-satu', 'Salah satu dari obat di atas', 2],
                        ['lain', 'Obat lain atau tidak ada', 1],
                    ], true),
                ],
            ]],
            'scoring' => ['bands' => [
                ['min' => 7, 'max' => 11, 'risk_level' => 'rendah', 'interpretation' => 'Risiko rendah — pencegahan jatuh dasar.'],
                ['min' => 12, 'max' => 23, 'risk_level' => 'tinggi', 'interpretation' => 'Risiko tinggi — terapkan pencegahan jatuh tingkat tinggi.'],
            ]],
        ];
    }

    /** Braden Scale — risiko dekubitus. */
    private function braden(): array
    {
        $skala = fn (string $kunci, string $label, array $pilihan) => $this->pilihan($kunci, $label, $pilihan, true);

        return [
            'code' => 'risiko-dekubitus',
            'name' => 'Pengkajian Risiko Dekubitus (Braden Scale)',
            'category' => FormTemplate::PENGKAJIAN_LANJUTAN,
            'is_repeatable' => true,
            'note' => 'Braden Scale. Skor makin RENDAH berarti risiko makin tinggi — kebalikan dari '
                . 'Morse. Perlu ditinjau komite medik sebelum disahkan.',
            'sections' => [[
                'title' => 'Faktor risiko dekubitus',
                'questions' => [
                    $skala('persepsi-sensori', 'Persepsi sensori', [
                        ['1', 'Terbatas sepenuhnya', 1], ['2', 'Sangat terbatas', 2],
                        ['3', 'Agak terbatas', 3], ['4', 'Tidak terbatas', 4],
                    ]),
                    $skala('kelembapan', 'Kelembapan kulit', [
                        ['1', 'Selalu lembap', 1], ['2', 'Sangat lembap', 2],
                        ['3', 'Kadang lembap', 3], ['4', 'Jarang lembap', 4],
                    ]),
                    $skala('aktivitas', 'Aktivitas', [
                        ['1', 'Berbaring terus', 1], ['2', 'Terbatas di kursi', 2],
                        ['3', 'Kadang berjalan', 3], ['4', 'Berjalan sering', 4],
                    ]),
                    $skala('mobilitas', 'Mobilitas', [
                        ['1', 'Tidak mampu bergerak', 1], ['2', 'Sangat terbatas', 2],
                        ['3', 'Agak terbatas', 3], ['4', 'Tidak terbatas', 4],
                    ]),
                    $skala('nutrisi', 'Pola makan', [
                        ['1', 'Sangat buruk', 1], ['2', 'Kemungkinan tidak adekuat', 2],
                        ['3', 'Adekuat', 3], ['4', 'Sangat baik', 4],
                    ]),
                    $skala('gesekan', 'Gesekan dan pergeseran', [
                        ['1', 'Bermasalah', 1], ['2', 'Potensial bermasalah', 2],
                        ['3', 'Tidak menimbulkan masalah', 3],
                    ]),
                ],
            ]],
            'scoring' => ['bands' => [
                ['min' => 0, 'max' => 9, 'risk_level' => 'sangat-tinggi', 'interpretation' => 'Risiko sangat tinggi.'],
                ['min' => 10, 'max' => 12, 'risk_level' => 'tinggi', 'interpretation' => 'Risiko tinggi.'],
                ['min' => 13, 'max' => 14, 'risk_level' => 'sedang', 'interpretation' => 'Risiko sedang.'],
                ['min' => 15, 'max' => 18, 'risk_level' => 'rendah', 'interpretation' => 'Risiko rendah.'],
                ['min' => 19, 'max' => 23, 'risk_level' => 'tidak-berisiko', 'interpretation' => 'Tidak berisiko.'],
            ]],
        ];
    }

    /** Aldrete Score — pemulihan pasca anestesi umum. */
    private function aldrete(): array
    {
        return [
            'code' => 'skor-aldrete',
            'name' => 'Skor Aldrete Pasca Anestesi',
            'category' => FormTemplate::PENGKAJIAN_LANJUTAN,
            'is_repeatable' => true,
            'note' => 'Aldrete Score untuk anestesi umum. Skor 8 ke atas lazim dipakai sebagai syarat '
                . 'pindah dari ruang pulih. Perlu ditinjau komite medik sebelum disahkan.',
            'sections' => [[
                'title' => 'Pemulihan pasca anestesi',
                'questions' => [
                    $this->pilihan('aktivitas', 'Aktivitas motorik', [
                        ['0', 'Tidak mampu menggerakkan ekstremitas', 0],
                        ['1', 'Mampu menggerakkan 2 ekstremitas', 1],
                        ['2', 'Mampu menggerakkan 4 ekstremitas', 2],
                    ], true),
                    $this->pilihan('respirasi', 'Respirasi', [
                        ['0', 'Apnea', 0],
                        ['1', 'Dispnea atau napas dangkal', 1],
                        ['2', 'Mampu napas dalam dan batuk bebas', 2],
                    ], true),
                    $this->pilihan('sirkulasi', 'Sirkulasi (tekanan darah)', [
                        ['0', 'Berbeda lebih dari 50% dari nilai awal', 0],
                        ['1', 'Berbeda 20-50% dari nilai awal', 1],
                        ['2', 'Berbeda kurang dari 20% dari nilai awal', 2],
                    ], true),
                    $this->pilihan('kesadaran', 'Kesadaran', [
                        ['0', 'Tidak berespons', 0],
                        ['1', 'Bangun bila dipanggil', 1],
                        ['2', 'Sadar penuh', 2],
                    ], true),
                    $this->pilihan('saturasi', 'Saturasi oksigen', [
                        ['0', 'Kurang dari 90% meski dengan oksigen', 0],
                        ['1', 'Perlu oksigen untuk mempertahankan di atas 90%', 1],
                        ['2', 'Di atas 92% dengan udara ruangan', 2],
                    ], true),
                ],
            ]],
            'scoring' => ['bands' => [
                ['min' => 0, 'max' => 7, 'risk_level' => 'belum-layak', 'interpretation' => 'Belum layak pindah dari ruang pulih; lanjutkan pemantauan.'],
                ['min' => 8, 'max' => 10, 'risk_level' => 'layak', 'interpretation' => 'Layak dipindahkan dari ruang pulih.'],
            ]],
        ];
    }

    /** Bromage Score — pemulihan pasca anestesi spinal. */
    private function bromage(): array
    {
        return [
            'code' => 'skor-bromage',
            'name' => 'Skor Bromage Pasca Anestesi Spinal',
            'category' => FormTemplate::PENGKAJIAN_LANJUTAN,
            'is_repeatable' => true,
            'note' => 'Bromage Score untuk anestesi spinal. Berbeda dari Aldrete: ia SATU skala, '
                . 'bukan penjumlahan beberapa butir. Perlu ditinjau komite medik sebelum disahkan.',
            'sections' => [[
                'title' => 'Blokade motorik',
                'questions' => [
                    $this->pilihan('blokade', 'Kemampuan gerak ekstremitas bawah', [
                        ['0', 'Gerakan penuh tungkai', 0],
                        ['1', 'Tidak mampu ekstensi tungkai', 1],
                        ['2', 'Tidak mampu fleksi lutut', 2],
                        ['3', 'Tidak mampu fleksi pergelangan kaki', 3],
                    ], true),
                ],
            ]],
            'scoring' => ['bands' => [
                ['min' => 0, 'max' => 1, 'risk_level' => 'layak', 'interpretation' => 'Blokade motorik sudah pulih; layak dipindahkan.'],
                ['min' => 2, 'max' => 3, 'risk_level' => 'belum-layak', 'interpretation' => 'Blokade motorik belum pulih; lanjutkan pemantauan.'],
            ]],
        ];
    }

    /** Steward Score — pemulihan pasca anestesi pada anak. */
    private function steward(): array
    {
        return [
            'code' => 'skor-steward',
            'name' => 'Skor Steward Pasca Anestesi Anak',
            'category' => FormTemplate::PENGKAJIAN_LANJUTAN,
            'age_group' => 'anak',
            'is_repeatable' => true,
            'note' => 'Steward Score untuk pemulihan pasca anestesi pada anak. '
                . 'Perlu ditinjau komite medik sebelum disahkan.',
            'sections' => [[
                'title' => 'Pemulihan pasca anestesi anak',
                'questions' => [
                    $this->pilihan('kesadaran', 'Kesadaran', [
                        ['0', 'Tidak berespons', 0],
                        ['1', 'Berespons terhadap rangsang', 1],
                        ['2', 'Sadar penuh', 2],
                    ], true),
                    $this->pilihan('respirasi', 'Jalan napas', [
                        ['0', 'Perlu bantuan napas', 0],
                        ['1', 'Mempertahankan jalan napas dengan baik', 1],
                        ['2', 'Batuk atas perintah atau menangis', 2],
                    ], true),
                    $this->pilihan('motorik', 'Aktivitas motorik', [
                        ['0', 'Tidak bergerak', 0],
                        ['1', 'Gerakan tanpa tujuan', 1],
                        ['2', 'Gerakan bertujuan', 2],
                    ], true),
                ],
            ]],
            'scoring' => ['bands' => [
                ['min' => 0, 'max' => 4, 'risk_level' => 'belum-layak', 'interpretation' => 'Belum layak pindah; lanjutkan pemantauan.'],
                ['min' => 5, 'max' => 6, 'risk_level' => 'layak', 'interpretation' => 'Layak dipindahkan dari ruang pulih.'],
            ]],
        ];
    }

    /** Early Warning Score dewasa — mengikuti parameter pemantauan_pews_dewasa Khanza. */
    private function ewsDewasa(): array
    {
        return [
            'code' => 'ews-dewasa',
            'name' => 'Pemantauan Early Warning Score Dewasa',
            'category' => FormTemplate::PENGKAJIAN_LANJUTAN,
            'age_group' => 'dewasa',
            'is_repeatable' => true,
            'note' => 'Parameter dan pengelompokannya mengikuti tabel pemantauan_pews_dewasa. '
                . 'Bobot skor mengikuti EWS yang lazim dipakai; RSP UI perlu menyesuaikannya dengan '
                . 'pedoman code blue yang berlaku sebelum disahkan.',
            'sections' => [[
                'title' => 'Parameter fisiologis',
                'questions' => [
                    $this->pilihan('laju-respirasi', 'Laju respirasi (x/menit)', [
                        ['<=5', '5 atau kurang', 3], ['6-8', '6 - 8', 3], ['9-11', '9 - 11', 1],
                        ['12-20', '12 - 20', 0], ['21-24', '21 - 24', 2], ['25-34', '25 - 34', 3],
                        ['>=35', '35 atau lebih', 3],
                    ], true),
                    $this->pilihan('saturasi-oksigen', 'Saturasi oksigen (%)', [
                        ['>=95', '95 atau lebih', 0], ['94-95', '94 - 95', 1],
                        ['92-93', '92 - 93', 2], ['<=92', '92 atau kurang', 3],
                    ], true),
                    $this->pilihan('suplemen-oksigen', 'Mendapat suplemen oksigen', [
                        ['tidak', 'Tidak', 0], ['ya', 'Ya', 2],
                    ], true),
                    $this->pilihan('tekanan-darah-sistolik', 'Tekanan darah sistolik (mmHg)', [
                        ['>=220', '220 atau lebih', 3], ['181-220', '181 - 220', 2],
                        ['111-180', '111 - 180', 0], ['101-110', '101 - 110', 1],
                        ['91-100', '91 - 100', 2], ['71-90', '71 - 90', 3],
                        ['<=70', '70 atau kurang', 3],
                    ], true),
                    $this->pilihan('laju-jantung', 'Laju jantung (x/menit)', [
                        ['>=140', '140 atau lebih', 3], ['131-140', '131 - 140', 2],
                        ['111-130', '111 - 130', 2], ['91-110', '91 - 110', 1],
                        ['51-90', '51 - 90', 0], ['41-50', '41 - 50', 1],
                        ['<=40', '40 atau kurang', 3],
                    ], true),
                    $this->pilihan('kesadaran', 'Tingkat kesadaran', [
                        ['sadar', 'Sadar', 0], ['nyeri-verbal', 'Berespons nyeri atau verbal', 3],
                        ['unrespon', 'Tidak berespons', 3],
                    ], true),
                    $this->pilihan('temperatur', 'Temperatur (°C)', [
                        ['<=35', '35 atau kurang', 3], ['35.1-36', '35,1 - 36', 1],
                        ['36.1-38', '36,1 - 38', 0], ['38.1-39', '38,1 - 39', 1],
                        ['>=39', '39 atau lebih', 2],
                    ], true),
                ],
            ]],
            'scoring' => ['bands' => [
                ['min' => 0, 'max' => 2, 'risk_level' => 'rendah', 'interpretation' => 'Pemantauan rutin sesuai jadwal bangsal.'],
                ['min' => 3, 'max' => 4, 'risk_level' => 'sedang', 'interpretation' => 'Tingkatkan frekuensi pemantauan; laporkan perawat penanggung jawab.'],
                ['min' => 5, 'max' => 6, 'risk_level' => 'tinggi', 'interpretation' => 'Laporkan DPJP; pertimbangkan perawatan tingkat lebih tinggi.'],
                ['min' => 7, 'max' => 30, 'risk_level' => 'kritis', 'interpretation' => 'Aktifkan tim reaksi cepat.'],
            ]],
        ];
    }

    /**
     * @param  array<int, array{0: string, 1: string, 2: int}>  $pilihan
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
                fn (array $o) => ['value' => $o[0], 'label' => $o[1], 'score' => $o[2]],
                $pilihan
            ),
        ];
    }
}
