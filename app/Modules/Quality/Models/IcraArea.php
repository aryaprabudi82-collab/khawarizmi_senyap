<?php

namespace App\Modules\Quality\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Area rumah sakit berikut kelompok risikonya.
 *
 * LAHIR KOSONG: nama kelompoknya dari pedoman, tapi ruang mana masuk
 * kelompok mana adalah keputusan RSP UI — ruang endoskopi bisa masuk
 * Tinggi di satu rumah sakit dan Sangat Tinggi di rumah sakit lain,
 * tergantung layanan apa yang ada di sebelahnya.
 */
class IcraArea extends Model
{
    protected $table = 'quality.icra_areas';

    protected $guarded = ['id'];

    public function riskGroup(): BelongsTo
    {
        return $this->belongsTo(IcraRiskGroup::class, 'risk_group_id');
    }

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }
}
