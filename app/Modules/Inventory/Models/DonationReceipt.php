<?php

namespace App\Modules\Inventory\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** hibah_non_medis. */
class DonationReceipt extends Model
{
    protected $table = 'inventory.donation_receipts';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['received_at' => 'datetime'];
    }

    public function donor(): BelongsTo
    {
        return $this->belongsTo(Donor::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(DonationReceiptItem::class, 'donation_receipt_id');
    }
}
