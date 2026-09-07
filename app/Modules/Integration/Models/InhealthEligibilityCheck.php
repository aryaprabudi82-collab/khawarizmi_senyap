<?php

namespace App\Modules\Integration\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Satu pemeriksaan eligibilitas peserta Inhealth.
 *
 * is_eligible null berarti PEMERIKSAANNYA GAGAL, bukan "tidak eligible" —
 * dua keadaan yang menuntut tindakan berbeda dari petugas loket.
 */
class InhealthEligibilityCheck extends Model
{
    protected $table = 'integration.inhealth_eligibility_checks';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'is_eligible' => 'boolean',
            'service_date' => 'date',
            'response_payload' => 'array',
            'checked_at' => 'datetime',
        ];
    }
}
