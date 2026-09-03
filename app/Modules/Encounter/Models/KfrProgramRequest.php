<?php

namespace App\Modules\Encounter\Models;

use Illuminate\Database\Eloquent\Model;

class KfrProgramRequest extends Model
{
    public const STATUS_DIMINTA = 'diminta';
    public const STATUS_DIBATALKAN = 'dibatalkan';

    protected $table = 'encounter.kfr_program_requests';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['requested_at' => 'datetime'];
    }
}
