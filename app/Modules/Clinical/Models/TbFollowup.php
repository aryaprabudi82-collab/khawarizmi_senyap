<?php

namespace App\Modules\Clinical\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Pemeriksaan lanjutan selama pengobatan TB.
 *
 * Tahapnya kosakata tertutup dari program nasional: pemeriksaan akhir
 * tahap awal, sisipan, bulan ke-5, dan akhir pengobatan masing-masing
 * punya arti yang berbeda pada penilaian hasil akhir.
 */
class TbFollowup extends Model
{
    protected $table = 'clinical.tb_followups';

    protected $guarded = ['id'];

    public const SEBELUM_PENGOBATAN = 'sebelum-pengobatan';

    public const AKHIR_TAHAP_AWAL = 'akhir-tahap-awal';

    public const SISIPAN = 'sisipan';

    public const BULAN_KE_5 = 'bulan-ke-5';

    public const AKHIR_PENGOBATAN = 'akhir-pengobatan';

    public const TAHAP = [
        self::SEBELUM_PENGOBATAN => 'Sebelum pengobatan',
        self::AKHIR_TAHAP_AWAL => 'Akhir tahap awal',
        self::SISIPAN => 'Sisipan',
        self::BULAN_KE_5 => 'Bulan ke-5',
        self::AKHIR_PENGOBATAN => 'Akhir pengobatan',
    ];

    public const HASIL_BTA = [
        'negatif' => 'Negatif',
        'scanty' => 'Scanty (1-19 BTA)',
        '1+' => '1+',
        '2+' => '2+',
        '3+' => '3+',
        'tidak-dilakukan' => 'Tidak dilakukan',
    ];

    protected function casts(): array
    {
        return [
            'examined_on' => 'date',
        ];
    }

    public function tbCase(): BelongsTo
    {
        return $this->belongsTo(TbCase::class, 'tb_case_id');
    }

    /**
     * Hasilnya negatif — dan "tidak dilakukan" BUKAN negatif.
     *
     * Pembedaan ini yang menahan pemeriksaan yang tidak pernah
     * dikerjakan terbaca sebagai bukti kesembuhan.
     */
    public function isNegative(): bool
    {
        return $this->smear_result === 'negatif';
    }

    public function isPositive(): bool
    {
        return in_array($this->smear_result, ['scanty', '1+', '2+', '3+'], true);
    }
}
