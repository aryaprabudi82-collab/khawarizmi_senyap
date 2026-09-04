<?php

namespace App\Modules\Pharmacy\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WardStockRequestItem extends Model
{
    protected $table = 'pharmacy.ward_stock_request_items';

    protected $guarded = ['id'];

    public $timestamps = false;

    public function request(): BelongsTo
    {
        return $this->belongsTo(WardStockRequest::class, 'request_id');
    }

    public function drug(): BelongsTo
    {
        return $this->belongsTo(Drug::class);
    }
}
