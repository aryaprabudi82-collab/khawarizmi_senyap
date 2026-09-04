<?php

namespace App\Modules\Asset\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DonationReceiptItem extends Model
{
    protected $table = 'asset.donation_receipt_items';

    protected $guarded = ['id'];

    public $timestamps = false;

    public function donationReceipt(): BelongsTo
    {
        return $this->belongsTo(DonationReceipt::class);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(AssetCategory::class, 'category_id');
    }
}
