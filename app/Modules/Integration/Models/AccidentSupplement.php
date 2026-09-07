<?php

namespace App\Modules\Integration\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Kunjungan lanjutan atas kejadian kecelakaan yang sudah tercatat.
 *
 * Satu kunjungan hanya boleh menunjuk satu kejadian — dua suplesi untuk
 * kunjungan yang sama akan ditagihkan dua kali atas pelayanan yang sama.
 */
class AccidentSupplement extends Model
{
    protected $table = 'integration.accident_supplements';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['service_date' => 'date', 'raw_response' => 'array'];
    }

    public function accident(): BelongsTo
    {
        return $this->belongsTo(AccidentRecord::class, 'accident_record_id');
    }
}
