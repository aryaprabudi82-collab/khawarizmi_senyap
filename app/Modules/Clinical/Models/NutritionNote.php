<?php

namespace App\Modules\Clinical\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Catatan ADIME gizi.
 *
 * SATU BENTUK UNTUK DUA TABEL KHANZA: catatan_adime_gizi memuat kelima
 * huruf ADIME plus instruksi, monitoring_asuhan_gizi hanya memuat
 * monitoring dan evaluasi — dua huruf terakhir dari ADIME yang sama.
 */
class NutritionNote extends Model
{
    use SoftDeletes;

    protected $table = 'clinical.nutrition_notes';

    protected $guarded = ['id'];

    public const ADIME = 'adime';

    public const MONITORING = 'monitoring';

    public const BAGIAN = [
        'assessment' => 'Asesmen',
        'diagnosis' => 'Diagnosis gizi',
        'intervention' => 'Intervensi',
        'monitoring' => 'Monitoring',
        'evaluation' => 'Evaluasi',
        'instruction' => 'Instruksi',
    ];

    protected function casts(): array
    {
        return [
            'noted_at' => 'datetime',
        ];
    }

    public function assessment(): BelongsTo
    {
        return $this->belongsTo(NutritionAssessment::class, 'assessment_id');
    }

    /**
     * Bagian ADIME yang terisi pada catatan ini.
     *
     * @return array<int, string>
     */
    public function filledParts(): array
    {
        $terisi = [];

        foreach (self::BAGIAN as $kolom => $label) {
            if (filled($this->{$kolom})) {
                $terisi[] = $label;
            }
        }

        return $terisi;
    }
}
