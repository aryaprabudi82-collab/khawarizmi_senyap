<?php

namespace App\Modules\Integration\Models;

use Illuminate\Database\Eloquent\Model;

/** Satu percobaan uji koneksi ke sistem luar. */
class IntegrationHealthCheck extends Model
{
    protected $table = 'integration.integration_health_checks';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['success' => 'boolean', 'checked_at' => 'datetime'];
    }
}
