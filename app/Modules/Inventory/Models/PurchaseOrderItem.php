<?php

namespace App\Modules\Inventory\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PurchaseOrderItem extends Model
{
    protected $table = 'inventory.purchase_order_items';

    protected $guarded = ['id'];

    public $timestamps = false;

    protected function casts(): array
    {
        return ['unit_price' => 'decimal:2'];
    }

    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class);
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }

    public function remainingQuantity(): float
    {
        return (float) $this->quantity_ordered - (float) $this->quantity_received;
    }
}
