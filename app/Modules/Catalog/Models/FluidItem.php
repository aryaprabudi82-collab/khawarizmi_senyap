<?php

namespace App\Modules\Catalog\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Satu jenis cairan masuk atau keluar.
 *
 * ARAHNYA MELEKAT DI SINI, bukan diisi setiap kali mencatat: urine selalu
 * keluar dan infus selalu masuk, dan arah yang bisa dikirim terpisah
 * membuka celah urine tercatat sebagai asupan.
 */
class FluidItem extends Model
{
    protected $table = 'catalog.fluid_items';

    protected $guarded = ['id'];

    public const MASUK = 'masuk';
    public const KELUAR = 'keluar';

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }
}
