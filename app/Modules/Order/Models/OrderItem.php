<?php

namespace App\Modules\Order\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class OrderItem extends Model
{
    protected $table = 'orders.order_items';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'reference_low' => 'decimal:2',
            'reference_high' => 'decimal:2',
            'unit_price' => 'decimal:2',
            'result_numeric' => 'decimal:2',
            'is_abnormal' => 'boolean',
            'entered_at' => 'datetime',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(LabRadiologyOrder::class, 'order_id');
    }

    public function images(): HasMany
    {
        return $this->hasMany(OrderItemImage::class);
    }

    public function hasResult(): bool
    {
        return $this->result_numeric !== null || $this->result_text !== null || filled($this->result_notes);
    }

    /** Rentang rujukan yang disalin saat order dibuat, dibaca manusia. */
    public function referenceDisplay(): ?string
    {
        if ($this->result_type === TestCatalog::RESULT_KUANTITATIF && $this->reference_low !== null) {
            return $this->reference_low . '-' . $this->reference_high . ($this->unit ? ' ' . $this->unit : '');
        }

        return $this->reference_text;
    }
}
