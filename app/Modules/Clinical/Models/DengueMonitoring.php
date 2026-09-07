<?php

namespace App\Modules\Clinical\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Pemantauan demam berdarah.
 *
 * SUMBER TIAP NILAI IKUT DICATAT. Hematokrit dari laboratorium dan
 * hematokrit point-of-care bukan angka yang setara, sementara keputusan
 * pada demam berdarah bersandar pada kenaikan hematokrit 20 persen —
 * dan mencampur keduanya membuat kenaikan yang berasal dari pergantian
 * alat terbaca sebagai perburukan pasien.
 */
class DengueMonitoring extends Model
{
    use SoftDeletes;

    protected $table = 'clinical.dengue_monitorings';

    protected $guarded = ['id'];

    public const LABORATORIUM = 'laboratorium';

    public const POINT_OF_CARE = 'point-of-care';

    /**
     * Kenaikan hematokrit yang menandakan kebocoran plasma.
     *
     * 20 persen terhadap nilai dasar adalah ambang yang dipakai luas
     * dalam tata laksana demam berdarah.
     */
    public const AMBANG_KENAIKAN_HEMATOKRIT = 0.20;

    /** Ambang trombosit yang menandakan risiko perdarahan. */
    public const AMBANG_TROMBOSIT = 100000;

    protected function casts(): array
    {
        return [
            'observed_at' => 'datetime',
            'haemoglobin_g_dl' => 'decimal:1',
            'haematocrit_percent' => 'decimal:1',
        ];
    }

    public function isTraceable(): bool
    {
        return $this->source === self::LABORATORIUM && $this->order_id !== null;
    }

    public function hasLowPlatelets(): ?bool
    {
        return $this->platelets_per_ul === null
            ? null
            : $this->platelets_per_ul < self::AMBANG_TROMBOSIT;
    }

    /**
     * Kenaikan hematokrit terhadap sebuah nilai dasar.
     *
     * HANYA DIBANDINGKAN DENGAN SUMBER YANG SAMA. Mengembalikan null
     * bila alatnya berbeda — itu bukan kekurangan melainkan inti
     * aturannya: perbandingan lintas alat tidak bisa dipertanggung
     * jawabkan pada keputusan sepenting ini.
     */
    public function haematocritRiseFrom(self $baseline): ?float
    {
        if ($this->source !== $baseline->source) {
            return null;
        }

        if ($this->haematocrit_percent === null || $baseline->haematocrit_percent === null) {
            return null;
        }

        $dasar = (float) $baseline->haematocrit_percent;

        if ($dasar <= 0) {
            return null;
        }

        return round(((float) $this->haematocrit_percent - $dasar) / $dasar, 4);
    }

    public function isPlasmaLeakageFrom(self $baseline): ?bool
    {
        $kenaikan = $this->haematocritRiseFrom($baseline);

        return $kenaikan === null ? null : $kenaikan >= self::AMBANG_KENAIKAN_HEMATOKRIT;
    }
}
