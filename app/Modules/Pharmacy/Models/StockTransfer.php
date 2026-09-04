<?php

namespace App\Modules\Pharmacy\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** mutasi_barang (DlgMutasiBarang) — transfer stok antar lokasi. */
class StockTransfer extends Model
{
    protected $table = 'pharmacy.stock_transfers';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['transferred_at' => 'datetime'];
    }

    public function fromLocation(): BelongsTo
    {
        return $this->belongsTo(StockLocation::class, 'from_location_id');
    }

    public function toLocation(): BelongsTo
    {
        return $this->belongsTo(StockLocation::class, 'to_location_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(StockTransferItem::class, 'transfer_id');
    }
}
