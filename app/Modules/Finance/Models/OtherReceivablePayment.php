<?php

namespace App\Modules\Finance\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OtherReceivablePayment extends Model
{
    protected $table = 'finance.other_receivable_payments';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['paid_on' => 'date', 'amount' => 'decimal:2'];
    }

    public function receivable(): BelongsTo
    {
        return $this->belongsTo(OtherReceivable::class, 'receivable_id');
    }
}
