<?php

namespace App\Modules\Pharmacy\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProcedureBhpUsageItem extends Model
{
    protected $table = 'pharmacy.procedure_bhp_usage_items';

    protected $guarded = ['id'];

    public $timestamps = false;

    public function usage(): BelongsTo
    {
        return $this->belongsTo(ProcedureBhpUsage::class, 'usage_id');
    }

    public function drug(): BelongsTo
    {
        return $this->belongsTo(Drug::class);
    }
}
