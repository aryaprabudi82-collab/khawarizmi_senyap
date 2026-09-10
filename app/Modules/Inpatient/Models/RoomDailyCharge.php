<?php

namespace App\Modules\Inpatient\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Biaya harian tambahan yang menempel pada sebuah kamar.
 *
 * `biaya_harian` Khanza berkunci (kd_kamar, nama_biaya). Dengan NAMA
 * sebagai bagian kunci, memperbaiki ejaan "Asuhan Keperawtan" bukan
 * mengoreksi baris — ia membuat baris kedua, dan yang salah eja tinggal di
 * sana ikut tertagih. Di sini kodenya yang jadi penanda.
 */
class RoomDailyCharge extends Model
{
    protected $table = 'inpatient.room_daily_charges';

    protected $guarded = ['id'];

    public function room(): BelongsTo
    {
        return $this->belongsTo(Room::class, 'room_id');
    }

    /** Kosongnya effective_until berarti masih berlaku. */
    public function masihBerlaku(): bool
    {
        return $this->effective_until === null;
    }

    /**
     * Nilai yang tertagih per hari rawat.
     *
     * DIHITUNG, tidak disimpan: total yang dibekukan jadi kolom akan salah
     * begitu jumlahnya dikoreksi, dan tidak ada yang memberitahu.
     */
    public function totalHarian(): string
    {
        return bcmul((string) $this->amount, (string) $this->quantity, 2);
    }

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'effective_from' => 'date',
            'effective_until' => 'date',
        ];
    }
}
