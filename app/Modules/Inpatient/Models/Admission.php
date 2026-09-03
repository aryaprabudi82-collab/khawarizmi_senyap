<?php

namespace App\Modules\Inpatient\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Admission extends Model
{
    public const STATUS_DIRAWAT = 'dirawat';
    public const STATUS_PULANG = 'pulang';

    public const DISCHARGE_STATUSES = ['sembuh', 'rujuk', 'aps', 'meninggal', 'lain'];

    protected $table = 'inpatient.admissions';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'admitted_at' => 'datetime',
            'discharged_at' => 'datetime',
        ];
    }

    public function bed(): BelongsTo
    {
        return $this->belongsTo(Bed::class);
    }

    public function lengthOfStayDays(): int
    {
        $sampai = $this->discharged_at ?? now();

        return (int) $this->admitted_at->diffInDays($sampai);
    }
}
