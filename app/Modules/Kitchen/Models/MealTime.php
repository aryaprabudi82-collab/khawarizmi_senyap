<?php

namespace App\Modules\Kitchen\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Slot waktu makan pasien.
 *
 * `jam_diet_pasien` Khanza mengunci dua belas slot di dalam tipe kolom
 * enum('Pagi','Pagi2',...,'Malam3'). Menambah yang ketiga belas berarti
 * mengubah tipe kolom, dan "Pagi2" bukan nama yang berarti apa pun bagi
 * petugas gizi — ia nomor urut yang bocor ke dalam data.
 *
 * LAHIR KOSONG: jam makan RSP UI keputusan instalasi gizi, terikat jadwal
 * produksi dapur dan jadwal obat.
 */
class MealTime extends Model
{
    protected $table = 'kitchen.meal_times';

    protected $guarded = ['id'];

    /**
     * Slot yang sudah ada tapi jamnya belum ditetapkan.
     *
     * Daftar kejujuran: slot tanpa jam tidak bisa dijadwalkan pengantarannya,
     * dan itu lebih baik terlihat daripada terbaca sebagai tengah malam.
     */
    public function jamBelumDitetapkan(): bool
    {
        return $this->serve_at === null;
    }

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }
}
