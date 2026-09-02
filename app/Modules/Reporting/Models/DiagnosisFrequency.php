<?php

namespace App\Modules\Reporting\Models;

use Illuminate\Database\Eloquent\Model;

class DiagnosisFrequency extends Model
{
    public $timestamps = false;

    protected $table = 'reporting.diagnosis_frequency';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'report_date' => 'date',
            'synced_at' => 'datetime',
        ];
    }
}
