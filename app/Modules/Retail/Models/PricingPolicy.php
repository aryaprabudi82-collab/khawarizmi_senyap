<?php

namespace App\Modules\Retail\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Patokan marjin per tingkat harga, BERVERSI.
 *
 * `tokosetharga` Khanza satu baris tanpa kunci sama sekali: mengubah
 * patokan MENIMPA yang lama, dan pertanyaan "patokan mana yang berlaku
 * waktu barang ini dihargai" tidak punya jawaban.
 */
class PricingPolicy extends Model
{
    protected $table = 'retail.pricing_policies';

    protected $guarded = ['id'];

    public function tier(): BelongsTo
    {
        return $this->belongsTo(PriceTier::class, 'price_tier_id');
    }

    protected function casts(): array
    {
        return [
            'markup_percent' => 'decimal:2',
            'effective_from' => 'date',
            'is_active' => 'boolean',
        ];
    }
}
