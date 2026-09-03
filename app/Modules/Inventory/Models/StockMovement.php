<?php

namespace App\Modules\Inventory\Models;

use Illuminate\Database\Eloquent\Model;

class StockMovement extends Model
{
    public $timestamps = false;

    protected $table = 'inventory.stock_movements';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:2',
            'balance_after' => 'decimal:2',
            'moved_at' => 'datetime',
        ];
    }
}
