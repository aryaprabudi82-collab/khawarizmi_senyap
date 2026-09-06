<?php

namespace App\Modules\Finance\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PayablePayment extends Model
{
    protected $table = 'finance.payable_payments';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['paid_on' => 'date', 'amount' => 'decimal:2'];
    }

    public function payable(): BelongsTo
    {
        return $this->belongsTo(Payable::class, 'payable_id');
    }
}
