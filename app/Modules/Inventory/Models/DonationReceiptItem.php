<?php

namespace App\Modules\Inventory\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DonationReceiptItem extends Model
{
    protected $table = 'inventory.donation_receipt_items';

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
