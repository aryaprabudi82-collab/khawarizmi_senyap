<?php

namespace App\Modules\Correspondence\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Alasan menolak anjuran medis.
 *
 * Tabelnya sengaja tidak diisi seeder: kosakatanya diskresi RSP UI, dan
 * menebaknya melahirkan statistik resmi tentang kategori yang tidak
 * pernah disepakati siapa pun.
 */
class RefusalReason extends Model
{
    protected $table = 'correspondence.medical_advice_refusal_reasons';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }
}
