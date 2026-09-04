<?php

namespace App\Modules\Kitchen\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DonationReceiptItem extends Model
{
    protected $table = 'kitchen.donation_receipt_items';

    protected $guarded = ['id'];

    public $timestamps = false;

    public function donationReceipt(): BelongsTo
    {
        return $this->belongsTo(DonationReceipt::class);
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }
}
