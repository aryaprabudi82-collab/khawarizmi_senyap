<?php

namespace App\Modules\Inpatient\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Satu baris per periode penempatan bed — pola yang sama dengan
 * DpjpHistory. Inilah yang membuat biaya kamar per hari tetap benar
 * ketika pasien pindah kelas di tengah rawat.
 */
class BedAssignment extends Model
{
    protected $table = 'inpatient.bed_assignments';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'assigned_at' => 'datetime',
            'released_at' => 'datetime',
        ];
    }

    public function admission(): BelongsTo
    {
        return $this->belongsTo(Admission::class);
    }

    public function bed(): BelongsTo
    {
        return $this->belongsTo(Bed::class);
    }

    public function isOpen(): bool
    {
        return $this->released_at === null;
    }
}
