<?php

namespace App\Modules\Clinical\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Pembacaan parameter MESIN dialisis pada satu waktu.
 *
 * Tanda vital pasien sengaja tidak ada di sini: itu ukuran pasien, bukan
 * ukuran mesin, dan sudah punya rumahnya di panel observasi sejak item D.
 * catatan_observasi_hemodialisa Khanza mencampur keduanya.
 */
class DialysisObservation extends Model
{
    protected $table = 'clinical.dialysis_observations';

    protected $guarded = ['id'];

    /**
     * Tekanan vena yang tinggi menandakan aliran keluar terhambat —
     * bekuan di sirkuit atau masalah pada akses vaskular.
     *
     * Ambangnya praktik yang lazim, dipakai untuk MENYEBUTKAN, bukan
     * menghentikan apa pun.
     */
    public const AMBANG_TEKANAN_VENA = 200;

    protected function casts(): array
    {
        return [
            'observed_at' => 'datetime',
            'ultrafiltration_goal_l' => 'decimal:2',
        ];
    }

    public function session(): BelongsTo
    {
        return $this->belongsTo(DialysisSession::class, 'session_id');
    }

    public function hasHighVenousPressure(): bool
    {
        return $this->venous_pressure_mmhg !== null
            && $this->venous_pressure_mmhg > self::AMBANG_TEKANAN_VENA;
    }
}
