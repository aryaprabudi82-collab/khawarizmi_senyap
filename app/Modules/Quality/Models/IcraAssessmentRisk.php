<?php

namespace App\Modules\Quality\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Hasil identifikasi satu butir risiko pada satu pengkajian.
 *
 * `present` sengaja boleh kosong. Daftar periksa berkotak-centang biasa
 * cuma punya dua keadaan, dan yang tidak tercentang lalu terbaca "risiko
 * ini tidak ada" — padahal bisa saja tidak ada yang melihatnya. ICRA yang
 * berbunyi "tidak ada risiko kebakaran" tanpa ada yang memeriksanya
 * adalah dokumen yang akan dikutip setelah kebakaran terjadi.
 */
class IcraAssessmentRisk extends Model
{
    protected $table = 'quality.icra_assessment_risks';

    protected $guarded = ['id'];

    public function assessment(): BelongsTo
    {
        return $this->belongsTo(IcraAssessment::class, 'assessment_id');
    }

    public function riskItem(): BelongsTo
    {
        return $this->belongsTo(IcraRiskItem::class, 'risk_item_id');
    }

    public function belumDiperiksa(): bool
    {
        return $this->present === null;
    }

    protected function casts(): array
    {
        return ['present' => 'boolean'];
    }
}
