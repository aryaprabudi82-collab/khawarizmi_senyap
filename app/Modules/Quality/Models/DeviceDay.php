<?php

namespace App\Modules\Quality\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Penyebut surveilans HAIs: hari-alat dan hari-rawat per bangsal per hari.
 *
 * Tanpa baris ini angka HAIs hanya bisa disajikan sebagai jumlah mentah,
 * dan jumlah mentah membuat bangsal besar selalu terlihat lebih buruk.
 */
class DeviceDay extends Model
{
    protected $table = 'quality.device_days';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['counted_on' => 'date'];
    }
}
