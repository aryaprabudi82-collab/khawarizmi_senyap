<?php

namespace App\Modules\Pharmacy\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PatientStockRequestItem extends Model
{
    protected $table = 'pharmacy.patient_stock_request_items';

    protected $guarded = ['id'];

    public $timestamps = false;

    public function request(): BelongsTo
    {
        return $this->belongsTo(PatientStockRequest::class, 'request_id');
    }

    public function drug(): BelongsTo
    {
        return $this->belongsTo(Drug::class);
    }
}
