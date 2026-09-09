<?php

namespace App\Modules\Organization\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Ruang operasi.
 *
 * Menutup satu cacat yang sudah merusak laporan wajib: nama ruang operasi
 * sebelumnya teks bebas di clinical.operations DAN encounter.operation_bookings,
 * sementara laporan RL mengelompokkan berdasarkan teks itu — "OK 1" dan "OK1"
 * memecah satu ruang jadi dua baris tanpa satu pun galat muncul.
 *
 * LAHIR KOSONG: daftar ruang operasi adalah kenyataan fisik gedung RSP UI.
 */
class OperatingRoom extends Model
{
    protected $table = 'organization.operating_rooms';

    protected $guarded = ['id'];

    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class, 'unit_id');
    }

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }
}
