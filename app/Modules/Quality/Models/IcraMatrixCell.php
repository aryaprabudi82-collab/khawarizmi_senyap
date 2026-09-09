<?php

namespace App\Modules\Quality\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Satu sel matriks ICRA: tipe aktivitas x kelompok risiko -> kelas.
 *
 * `max_class_id` terisi hanya untuk sel yang standarnya menyerahkan
 * pilihan kepada komite pengendalian infeksi. Memaksanya jadi satu kelas
 * akan menyembunyikan keputusan yang standarnya justru mensyaratkan ada.
 */
class IcraMatrixCell extends Model
{
    protected $table = 'quality.icra_matrix';

    protected $guarded = ['id'];

    public function activityType(): BelongsTo
    {
        return $this->belongsTo(IcraActivityType::class, 'activity_type_id');
    }

    public function riskGroup(): BelongsTo
    {
        return $this->belongsTo(IcraRiskGroup::class, 'risk_group_id');
    }

    public function minClass(): BelongsTo
    {
        return $this->belongsTo(IcraPrecautionClass::class, 'min_class_id');
    }

    public function maxClass(): BelongsTo
    {
        return $this->belongsTo(IcraPrecautionClass::class, 'max_class_id');
    }

    /** Sel yang menuntut keputusan komite, bukan keluaran otomatis. */
    public function butuhKeputusanKomite(): bool
    {
        return $this->max_class_id !== null && $this->max_class_id !== $this->min_class_id;
    }
}
