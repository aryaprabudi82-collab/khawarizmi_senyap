<?php

namespace App\Modules\Integration\Models;

use Illuminate\Database\Eloquent\Model;

class BpjsEligibilityCheck extends Model
{
    public $timestamps = false;

    protected $table = 'integration.bpjs_eligibility_checks';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'service_date' => 'date',
            'is_eligible' => 'boolean',
            'response_payload' => 'array',
            'checked_at' => 'datetime',
        ];
    }
}
