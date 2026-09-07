<?php

namespace App\Modules\Catalog\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Satu versi template formulir asesmen atau skrining.
 *
 * VERSI ADALAH BAGIAN DARI IDENTITASNYA: tiap revisi jadi baris baru, dan
 * yang lama tidak pernah dihapus. Kalau ditimpa, asesmen yang diisi tahun
 * lalu akan dibaca ulang dengan pertanyaan dan ambang tahun ini.
 */
class FormTemplate extends Model
{
    protected $table = 'catalog.form_templates';

    protected $guarded = ['id'];

    public const ASESMEN_MEDIS = 'asesmen-medis';
    public const ASESMEN_KEPERAWATAN = 'asesmen-keperawatan';
    public const SKRINING = 'skrining';
    public const PENGKAJIAN_LANJUTAN = 'pengkajian-lanjutan';
    public const CHECKLIST = 'checklist';
    public const CATATAN = 'catatan';
    public const HASIL_PEMERIKSAAN = 'hasil-pemeriksaan';

    public const KATEGORI = [
        self::ASESMEN_MEDIS,
        self::ASESMEN_KEPERAWATAN,
        self::SKRINING,
        self::PENGKAJIAN_LANJUTAN,
        self::CHECKLIST,
        self::CATATAN,
        self::HASIL_PEMERIKSAAN,
    ];

    protected function casts(): array
    {
        return [
            'sections' => 'array',
            'scoring' => 'array',
            'is_active' => 'boolean',
            'is_repeatable' => 'boolean',
            'is_approved' => 'boolean',
            'approved_at' => 'datetime',
        ];
    }

    /**
     * Seluruh pertanyaan template ini, rata tanpa bagiannya.
     *
     * @return array<string, array<string, mixed>>
     */
    public function questions(): array
    {
        $hasil = [];

        foreach ($this->sections ?? [] as $bagian) {
            foreach ($bagian['questions'] ?? [] as $pertanyaan) {
                if (isset($pertanyaan['key'])) {
                    $hasil[$pertanyaan['key']] = $pertanyaan;
                }
            }
        }

        return $hasil;
    }

    /** Apakah template ini menghitung skor sama sekali. */
    public function isScored(): bool
    {
        return ! empty($this->scoring['bands'] ?? []);
    }
}
