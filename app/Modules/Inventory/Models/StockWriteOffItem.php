<?php

namespace App\Modules\Inventory\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Satu barang dalam pengajuan penghapusan stok.
 *
 * HARGANYA DIBEKUKAN. Barang yang dibuang tahun lalu hilang senilai
 * harga tahun lalu, dan membacanya ulang dari harga hari ini membuat
 * kerugian masa lalu ikut berubah setiap kali harga naik.
 */
class StockWriteOffItem extends Model
{
    protected $table = 'inventory.stock_write_off_items';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:2',
            'unit_price' => 'decimal:2',
            'amount' => 'decimal:2',
            'expires_on' => 'date',
        ];
    }

    public function writeOff(): BelongsTo
    {
        return $this->belongsTo(StockWriteOff::class, 'write_off_id');
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class, 'item_id');
    }
}
