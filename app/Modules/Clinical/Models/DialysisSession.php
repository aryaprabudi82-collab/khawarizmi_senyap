<?php

namespace App\Modules\Clinical\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Satu sesi hemodialisa.
 *
 * LAMA DIALISIS DIHITUNG dari jam mulai dan jam selesai. Khanza
 * menyimpan kolom `lama` yang diketik tanpa punya kedua jam itu — dan
 * durasi yang diketik cenderung terisi sesuai resep, bukan sesuai
 * kenyataan.
 */
class DialysisSession extends Model
{
    use SoftDeletes;

    protected $table = 'clinical.dialysis_sessions';

    protected $guarded = ['id'];

    public const BERJALAN = 'berjalan';

    public const SELESAI = 'selesai';

    public const DIHENTIKAN = 'dihentikan';

    public const DIBATALKAN = 'dibatalkan';

    public const AKSES = [
        'av-fistula' => 'AV fistula',
        'av-graft' => 'AV graft',
        'kateter-double-lumen' => 'Kateter double lumen',
        'kateter-tunneled' => 'Kateter tunneled',
    ];

    /**
     * Ambang kecukupan waktu: sesi yang tercapai kurang dari sekian
     * persen durasi resepnya dianggap tidak tuntas.
     *
     * Dipakai untuk MENYEBUTKAN, bukan menolak — sesi yang dihentikan
     * karena pasien hipotensi memang harus tercatat apa adanya.
     */
    public const AMBANG_KETERCAPAIAN = 0.9;

    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'ended_at' => 'datetime',
            'dry_weight_kg' => 'decimal:1',
            'pre_weight_kg' => 'decimal:1',
            'post_weight_kg' => 'decimal:1',
            'target_ultrafiltration_l' => 'decimal:2',
            'achieved_ultrafiltration_l' => 'decimal:2',
        ];
    }

    public function observations(): HasMany
    {
        return $this->hasMany(DialysisObservation::class, 'session_id')->orderBy('observed_at');
    }

    /** Lama dialisis yang benar-benar tercapai, dalam menit. */
    public function achievedMinutes(): ?int
    {
        if ($this->ended_at === null) {
            return null;
        }

        return (int) round($this->started_at->diffInMinutes($this->ended_at));
    }

    /**
     * Bagian durasi resep yang tercapai.
     *
     * Inilah yang tidak bisa dijawab Khanza: tanpa jam mulai dan selesai,
     * "lama" yang tercatat tidak bisa dibandingkan dengan apa pun.
     */
    public function timeAchievementRatio(): ?float
    {
        $tercapai = $this->achievedMinutes();

        if ($tercapai === null || ! $this->prescribed_minutes) {
            return null;
        }

        return round($tercapai / $this->prescribed_minutes, 3);
    }

    public function isTimeShortfall(): ?bool
    {
        $rasio = $this->timeAchievementRatio();

        return $rasio === null ? null : $rasio < self::AMBANG_KETERCAPAIAN;
    }

    /**
     * Target ultrafiltrasi menurut hitungan berat: berat sebelum
     * dikurangi berat kering.
     *
     * DIHITUNG, dan sengaja terpisah dari target yang diresepkan — yang
     * perlu terlihat justru selisih antara keduanya, karena penarikan
     * yang lebih sedikit dari hitungan biasanya keputusan yang disengaja.
     */
    public function arithmeticTargetL(): ?float
    {
        if ($this->pre_weight_kg === null || $this->dry_weight_kg === null) {
            return null;
        }

        $selisih = (float) $this->pre_weight_kg - (float) $this->dry_weight_kg;

        return $selisih <= 0 ? 0.0 : round($selisih, 2);
    }

    /** Selisih target resep terhadap hitungan berat. */
    public function targetDeviationL(): ?float
    {
        $aritmetik = $this->arithmeticTargetL();

        if ($aritmetik === null || $this->target_ultrafiltration_l === null) {
            return null;
        }

        return round((float) $this->target_ultrafiltration_l - $aritmetik, 2);
    }

    /**
     * Penurunan berat yang benar-benar terjadi.
     *
     * Pembanding independen bagi ultrafiltrasi yang dilaporkan mesin:
     * keduanya mestinya berdekatan, dan selisih besar berarti salah satu
     * pengukurannya keliru.
     */
    public function weightLossKg(): ?float
    {
        if ($this->pre_weight_kg === null || $this->post_weight_kg === null) {
            return null;
        }

        return round((float) $this->pre_weight_kg - (float) $this->post_weight_kg, 2);
    }

    public function isEditable(): bool
    {
        return in_array($this->status, [self::BERJALAN], true);
    }
}
