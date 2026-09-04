<?php

namespace App\Modules\Pharmacy\Models;

use Illuminate\Database\Eloquent\Model;

/** satuan_barang — dinamai MeasureUnit (bukan Unit) supaya tidak rancu dengan Organization\Models\Unit (unit/poliklinik RS). */
class MeasureUnit extends Model
{
    protected $table = 'pharmacy.units';

    protected $guarded = ['id'];
}
