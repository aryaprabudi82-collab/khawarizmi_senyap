<?php

namespace App\Modules\Pharmacy\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** retur_dari_pembeli + retur_piutang_pasien — satu mekanisme retur atas RetailSale, terlepas lunas/piutang. */
class RetailSaleReturn extends Model
{
    protected $table = 'pharmacy.retail_sale_returns';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['returned_at' => 'datetime'];
    }

    public function sale(): BelongsTo
    {
        return $this->belongsTo(RetailSale::class, 'sale_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(RetailSaleReturnItem::class, 'sale_return_id');
    }
}
