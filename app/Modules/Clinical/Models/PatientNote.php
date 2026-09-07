<?php

namespace App\Modules\Clinical\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Catatan yang berlaku untuk PASIEN, bukan untuk satu kunjungan.
 *
 * Isinya hal yang berlaku lintas kunjungan. Menempelkannya pada kunjungan
 * akan membuatnya hilang dari pandangan pada kunjungan berikutnya — justru
 * saat ia paling dibutuhkan.
 */
class PatientNote extends Model
{
    use SoftDeletes;

    protected $table = 'clinical.patient_notes';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['is_alert' => 'boolean'];
    }
}
