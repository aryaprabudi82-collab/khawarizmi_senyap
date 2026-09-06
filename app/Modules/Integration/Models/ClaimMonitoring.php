<?php

namespace App\Modules\Integration\Models;

use Illuminate\Database\Eloquent\Model;

class ClaimMonitoring extends Model
{
    protected $table = 'integration.claim_monitorings';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'period_from' => 'date',
            'period_until' => 'date',
            'success' => 'boolean',
            'raw_response' => 'array',
            'total_tariff' => 'decimal:2',
        ];
    }
}
