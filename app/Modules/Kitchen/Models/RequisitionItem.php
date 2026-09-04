<?php

namespace App\Modules\Kitchen\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RequisitionItem extends Model
{
    public $timestamps = false;

    protected $table = 'kitchen.requisition_items';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'quantity_requested' => 'decimal:2',
            'quantity_issued' => 'decimal:2',
        ];
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }

    public function requisition(): BelongsTo
    {
        return $this->belongsTo(Requisition::class);
    }
}
