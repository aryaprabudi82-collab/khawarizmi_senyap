<?php

namespace App\Modules\Inpatient\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Bed extends Model
{
    public const STATUS_TERSEDIA = 'tersedia';
    public const STATUS_TERISI = 'terisi';
    public const STATUS_DIBERSIHKAN = 'dibersihkan';
    public const STATUS_TIDAK_AKTIF = 'tidak-aktif';

    protected $table = 'inpatient.beds';

    protected $guarded = ['id'];

    public function room(): BelongsTo
    {
        return $this->belongsTo(Room::class);
    }

    public function currentAdmission(): HasOne
    {
        return $this->hasOne(Admission::class)->where('status', Admission::STATUS_DIRAWAT);
    }
}
