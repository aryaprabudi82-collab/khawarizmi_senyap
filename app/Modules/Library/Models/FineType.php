<?php

namespace App\Modules\Library\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Jenis denda selain keterlambatan (kerusakan, kehilangan).
 *
 * Daftarnya SENGAJA lahir kosong: besarannya diskresi RSP UI, dan
 * menebaknya berarti menagih pemustaka dengan angka yang tidak pernah
 * disepakati siapa pun. Denda keterlambatan tidak menunggu daftar ini —
 * besarannya keluar dari tarif harian yang dibekukan pada pinjamannya.
 */
class FineType extends Model
{
    protected $table = 'library.fine_types';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['amount' => 'decimal:2', 'is_active' => 'boolean'];
    }
}
