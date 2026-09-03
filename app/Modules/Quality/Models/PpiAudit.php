<?php

namespace App\Modules\Quality\Models;

use Illuminate\Database\Eloquent\Model;

class PpiAudit extends Model
{
    protected $table = 'quality.ppi_audits';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'audited_on' => 'date',
            'compliance_rate' => 'decimal:2',
        ];
    }
}
