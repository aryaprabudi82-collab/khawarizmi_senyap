<?php

namespace App\Modules\Order\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrderItemImage extends Model
{
    public $timestamps = false;

    protected $table = 'orders.order_item_images';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['uploaded_at' => 'datetime'];
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(OrderItem::class, 'order_item_id');
    }
}
