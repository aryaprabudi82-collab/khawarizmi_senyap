<?php

namespace App\Modules\Clinical\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Satu catatan cairan masuk atau keluar.
 *
 * Volume SELALU positif; arahnya yang menentukan tandanya. Saldonya tidak
 * pernah disimpan — ia dihitung dari baris-baris ini.
 */
class FluidBalanceEntry extends Model
{
    protected $table = 'clinical.fluid_balance_entries';

    protected $guarded = ['id'];

    public const MASUK = 'masuk';
    public const KELUAR = 'keluar';

    protected function casts(): array
    {
        return ['volume_ml' => 'decimal:2', 'recorded_at' => 'datetime'];
    }
}
