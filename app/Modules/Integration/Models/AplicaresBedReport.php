<?php

namespace App\Modules\Integration\Models;

use Illuminate\Database\Eloquent\Model;

class AplicaresBedReport extends Model
{
    protected $table = 'integration.aplicares_bed_reports';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['reported_at' => 'datetime', 'payload' => 'array', 'success' => 'boolean'];
    }
}
