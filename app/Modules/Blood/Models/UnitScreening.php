<?php

namespace App\Modules\Blood\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Hasil skrining IMLTD satu kantong darah.
 *
 * MELEKAT PADA KANTONG, BUKAN PADA DONOR. Berbeda dari serologi pasien
 * dialisis yang berlaku enam bulan: hasil skrining darah berlaku untuk
 * kantong itu saja, karena donor yang bersih bulan lalu bisa terinfeksi
 * minggu ini.
 */
class UnitScreening extends Model
{
    protected $table = 'blood.unit_screenings';

    protected $guarded = ['id'];

    public const NON_REAKTIF = 'non-reaktif';

    public const REAKTIF = 'reaktif';

    public const MERAGUKAN = 'meragukan';

    /**
     * Lima pemeriksaan Infeksi Menular Lewat Transfusi Darah.
     *
     * Kelimanya wajib pada setiap kantong sebelum boleh dikeluarkan —
     * bukan pilihan, dan bukan sebagian.
     */
    public const IMLTD = [
        'hbsag' => 'HBsAg (hepatitis B)',
        'anti_hcv' => 'Anti-HCV (hepatitis C)',
        'anti_hiv' => 'Anti-HIV',
        'syphilis' => 'Sifilis',
        'malaria' => 'Malaria',
    ];

    public const HASIL = [
        self::NON_REAKTIF => 'Non-reaktif',
        self::REAKTIF => 'Reaktif',
        self::MERAGUKAN => 'Meragukan',
    ];

    protected function casts(): array
    {
        return [
            'screened_at' => 'datetime',
            'is_repeat' => 'boolean',
        ];
    }

    public function bloodUnit(): BelongsTo
    {
        return $this->belongsTo(BloodUnit::class, 'blood_unit_id');
    }

    /**
     * Pemeriksaan yang hasilnya reaktif.
     *
     * @return array<int, string>
     */
    public function reactiveTests(): array
    {
        return $this->testsWith(self::REAKTIF);
    }

    /** @return array<int, string> */
    public function inconclusiveTests(): array
    {
        return $this->testsWith(self::MERAGUKAN);
    }

    /** Kantongnya lolos: kelima pemeriksaan non-reaktif. */
    public function isClear(): bool
    {
        return $this->reactiveTests() === [] && $this->inconclusiveTests() === [];
    }

    /** Satu hasil reaktif sudah cukup menolak kantongnya. */
    public function hasReactiveResult(): bool
    {
        return $this->reactiveTests() !== [];
    }

    /**
     * @return array<int, string>
     */
    private function testsWith(string $hasil): array
    {
        $cocok = [];

        foreach (self::IMLTD as $kolom => $label) {
            if ($this->{$kolom} === $hasil) {
                $cocok[] = $label;
            }
        }

        return $cocok;
    }
}
