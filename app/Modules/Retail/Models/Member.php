<?php

namespace App\Modules\Retail\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Member toko/koperasi.
 *
 * Tingkat harga melekat pada membernya: koperasi rumah sakit lazim
 * memberi harga karyawan, dan menyimpannya di sini membuat kasir tidak
 * perlu mengingat siapa dapat harga mana.
 */
class Member extends Model
{
    protected $table = 'retail.members';

    protected $guarded = ['id'];

    public function defaultPriceTier(): BelongsTo
    {
        return $this->belongsTo(PriceTier::class, 'default_price_tier_id');
    }

    public function sales(): HasMany
    {
        return $this->hasMany(Sale::class, 'member_id');
    }

    protected function casts(): array
    {
        return [
            'birth_date' => 'date',
            'joined_on' => 'date',
            'is_active' => 'boolean',
        ];
    }
}
