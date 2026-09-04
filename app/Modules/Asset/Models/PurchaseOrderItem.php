<?php

namespace App\Modules\Asset\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PurchaseOrderItem extends Model
{
    protected $table = 'asset.purchase_order_items';

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

    public function category(): BelongsTo
    {
        return $this->belongsTo(AssetCategory::class, 'category_id');
    }

    public function type(): BelongsTo
    {
        return $this->belongsTo(AssetType::class, 'type_id');
    }

    public function manufacturer(): BelongsTo
    {
        return $this->belongsTo(AssetManufacturer::class, 'manufacturer_id');
    }

    public function remainingQuantity(): float
    {
        return (float) $this->quantity_ordered - (float) $this->quantity_received;
    }
}
