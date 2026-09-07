<?php

namespace App\Modules\Clinical\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Catatan perawat: pelaksanaan rencana, evaluasi, atau pengamatan.
 *
 * Melengkapi proses keperawatan yang diagnosis dan perencanaannya dibangun
 * di item B. Tautan ke diagnosis bersifat OPSIONAL — tidak setiap catatan
 * menindaklanjuti satu masalah tertentu.
 */
class NursingNote extends Model
{
    protected $table = 'clinical.nursing_notes';

    protected $guarded = ['id'];

    public const IMPLEMENTASI = 'implementasi';
    public const EVALUASI = 'evaluasi';
    public const PENGAMATAN = 'pengamatan';

    public const JENIS = [self::IMPLEMENTASI, self::EVALUASI, self::PENGAMATAN];

    protected function casts(): array
    {
        return ['recorded_at' => 'datetime'];
    }

    public function diagnosis(): BelongsTo
    {
        return $this->belongsTo(NursingDiagnosis::class, 'nursing_diagnosis_id');
    }
}
