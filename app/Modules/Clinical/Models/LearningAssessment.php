<?php

namespace App\Modules\Clinical\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Pengkajian kebutuhan belajar pasien & keluarga.
 *
 * PERTANYAAN KEYAKINAN BOLEH KOSONG. Khanza mewajibkan
 * penyakitnya_merupakan enum('Ujian/Cobaan','Kutukan','Lain-lain')
 * sebagai NOT NULL — setiap pasien harus dikategorikan keyakinannya dari
 * dua pilihan itu. Lihat catatan migrasi.
 */
class LearningAssessment extends Model
{
    use SoftDeletes;

    protected $table = 'clinical.learning_assessments';

    protected $guarded = ['id'];

    /** Cara belajar; boleh lebih dari satu. */
    public const CARA_BELAJAR = [
        'menulis' => 'Membaca dan menulis',
        'audio-visual' => 'Audio-visual atau gambar',
        'diskusi' => 'Diskusi',
        'simulasi' => 'Simulasi atau peragaan',
        'praktik-langsung' => 'Praktik langsung',
    ];

    /** Hambatan belajar; pasien yang paling perlu diperhatikan punya beberapa sekaligus. */
    public const HAMBATAN = [
        'tidak-ada' => 'Tidak ada hambatan',
        'takut-gelisah' => 'Takut atau gelisah',
        'tidak-tertarik' => 'Tidak tertarik',
        'nyeri' => 'Nyeri atau tidak nyaman',
        'buta-huruf' => 'Buta huruf',
        'gangguan-kognitif' => 'Gangguan kognitif',
        'gangguan-penglihatan' => 'Gangguan penglihatan',
        'gangguan-pendengaran' => 'Gangguan pendengaran',
        'kelelahan' => 'Kelelahan',
        'hambatan-bahasa' => 'Hambatan bahasa',
        'lainnya' => 'Lainnya',
    ];

    /**
     * Kosakata keyakinan, DILEBARKAN dari dua pilihan Khanza dan tidak
     * wajib diisi.
     *
     * Dilebarkan bukan untuk melengkapi melainkan supaya petugas tidak
     * terpaksa memilih label yang jelas tidak sesuai; "lainnya" berikut
     * isian bebas tetap jadi jalan keluarnya, dan mengosongkannya sama
     * sekali tetap sah.
     */
    public const KEYAKINAN_PENYAKIT = [
        'ujian' => 'Ujian atau cobaan',
        'takdir' => 'Takdir yang diterima',
        'akibat-perilaku' => 'Akibat perilaku atau kebiasaan',
        'penyakit-biasa' => 'Penyakit biasa yang bisa diobati',
        'kutukan' => 'Kutukan',
        'lainnya' => 'Lainnya',
    ];

    public const PENGAMBIL_KEPUTUSAN = [
        'pasien-sendiri' => 'Pasien sendiri',
        'pasangan' => 'Pasangan',
        'orang-tua' => 'Orang tua',
        'anak' => 'Anak',
        'keluarga-musyawarah' => 'Keluarga secara musyawarah',
        'wali' => 'Wali',
        'lainnya' => 'Lainnya',
    ];

    public const KEYAKINAN_TERAPI = [
        'yakin-sembuh' => 'Yakin sembuh',
        'yakin-jika-kontrol' => 'Yakin sembuh bila kontrol teratur',
        'yakin-jika-minum-obat' => 'Yakin sembuh bila minum obat teratur',
        'pasrah' => 'Pasrah',
        'ragu' => 'Ragu terhadap terapinya',
        'lainnya' => 'Lainnya',
    ];

    protected function casts(): array
    {
        return [
            'assessed_at' => 'datetime',
            'needs_interpreter' => 'boolean',
            'uses_sign_language' => 'boolean',
            'learning_preferences' => 'array',
            'learning_barriers' => 'array',
        ];
    }

    /**
     * Hambatan yang benar-benar menghalangi, tidak termasuk penanda
     * "tidak ada hambatan".
     *
     * @return array<int, string>
     */
    public function actualBarriers(): array
    {
        return array_values(array_diff($this->learning_barriers ?? [], ['tidak-ada']));
    }

    /**
     * Edukasi pada pasien ini menuntut penyesuaian.
     *
     * Bukan sekadar "ada hambatan": tidak mampu menerima informasi,
     * butuh penerjemah, atau memakai bahasa isyarat sama-sama menuntut
     * cara penyampaian yang berbeda.
     */
    public function needsAdaptedEducation(): bool
    {
        return $this->actualBarriers() !== []
            || $this->needs_interpreter === true
            || $this->uses_sign_language === true
            || $this->learning_ability === 'tidak-mampu';
    }

    /**
     * Pertanyaan keyakinan yang belum ditanyakan.
     *
     * Disebutkan, TIDAK dipaksakan — daftarnya berguna bagi petugas yang
     * ingin melengkapi, bukan sebagai syarat menutup apa pun.
     *
     * @return array<int, string>
     */
    public function unansweredBeliefQuestions(): array
    {
        $belum = [];

        foreach ([
            'illness_belief' => 'keyakinan tentang penyakitnya',
            'decision_maker' => 'siapa yang mengambil keputusan',
            'therapy_belief' => 'keyakinan terhadap terapi',
        ] as $kolom => $label) {
            if ($this->{$kolom} === null) {
                $belum[] = $label;
            }
        }

        return $belum;
    }
}
