<?php

namespace App\Modules\Billing\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Definisi shift kasir.
 *
 * BARIS, BUKAN ENUM. `closing_kasir` Khanza mengunci empat shift di dalam
 * tipe kolom enum('Pagi','Siang','Sore','Malam'); rumah sakit yang membuka
 * shift kelima harus mengubah tipe kolom. Bentuk cacat yang sama dengan
 * `jam_diet_pasien`.
 *
 * LAHIR KOSONG: pembagian shift kasir RSP UI adalah keputusan operasional
 * yang terikat jam layanan dan jumlah petugas.
 */
class CashierShift extends Model
{
    protected $table = 'billing.cashier_shifts';

    protected $guarded = ['id'];

    public function closings(): HasMany
    {
        return $this->hasMany(CashierShiftClosing::class, 'shift_id');
    }

    protected function casts(): array
    {
        return [
            'crosses_midnight' => 'boolean',
            'is_active' => 'boolean',
        ];
    }
}
