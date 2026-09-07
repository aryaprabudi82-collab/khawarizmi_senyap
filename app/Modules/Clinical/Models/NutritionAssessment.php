<?php

namespace App\Modules\Clinical\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Asuhan gizi — pengkajian ADIME oleh ahli gizi.
 *
 * ANTROPOMETRI DISIMPAN, INDEKSNYA DIHITUNG. Khanza menyimpan enam
 * indeks turunan sebagai kolom; di sini yang berumus baku dihitung, dan
 * yang butuh tabel standar pertumbuhan WHO dinyatakan belum tersedia
 * alih-alih dikarang. Lihat catatan migrasi.
 */
class NutritionAssessment extends Model
{
    use SoftDeletes;

    protected $table = 'clinical.nutrition_assessments';

    protected $guarded = ['id'];

    public const DRAF = 'draf';

    public const FINAL = 'final';

    public const DIBATALKAN = 'dibatalkan';

    /** Batas umur dewasa untuk rumus IMT dan berat badan ideal. */
    public const DEWASA_BULAN = 216;

    protected function casts(): array
    {
        return [
            'assessed_on' => 'date',
            'weight_kg' => 'decimal:2',
            'height_cm' => 'decimal:1',
            'mid_upper_arm_cm' => 'decimal:1',
            'knee_height_cm' => 'decimal:1',
            'ulna_length_cm' => 'decimal:1',
            'food_allergies' => 'array',
            'finalized_at' => 'datetime',
        ];
    }

    public function notes(): HasMany
    {
        return $this->hasMany(NutritionNote::class, 'assessment_id')->orderBy('noted_at');
    }

    public function isEditable(): bool
    {
        return $this->status === self::DRAF;
    }

    public function isAdult(): bool
    {
        return $this->age_months === null || $this->age_months >= self::DEWASA_BULAN;
    }

    /**
     * Indeks massa tubuh — padanan antropometri_imt Khanza, DIHITUNG.
     *
     * Berlaku untuk dewasa. Pada anak, angka IMT yang sama harus dibaca
     * terhadap umur (IMT/U), dan itu menuntut tabel WHO; karena itu di
     * sini IMT anak tetap dihitung tapi PENAFSIRANNYA tidak diberikan.
     */
    public function bmi(): ?float
    {
        if ($this->weight_kg === null || $this->height_cm === null) {
            return null;
        }

        $tinggiMeter = (float) $this->height_cm / 100;

        if ($tinggiMeter <= 0) {
            return null;
        }

        return round((float) $this->weight_kg / ($tinggiMeter ** 2), 1);
    }

    /**
     * Tafsiran IMT menurut ambang Asia-Pasifik WHO.
     *
     * HANYA UNTUK DEWASA. Untuk anak mengembalikan null, bukan tafsiran
     * dewasa yang dipaksakan: anak gemuk dan dewasa gemuk punya ambang
     * yang berbeda, dan memakai ambang dewasa pada anak menghasilkan
     * kesimpulan yang salah pada pasien yang paling rentan.
     */
    public function bmiCategory(): ?string
    {
        $imt = $this->bmi();

        if ($imt === null || ! $this->isAdult()) {
            return null;
        }

        return match (true) {
            $imt < 18.5 => 'kurang',
            $imt < 23.0 => 'normal',
            $imt < 25.0 => 'berisiko-lebih',
            $imt < 30.0 => 'obesitas-1',
            default => 'obesitas-2',
        };
    }

    /**
     * Berat badan ideal Broca — padanan antropometri_bbideal, DIHITUNG.
     *
     * Hanya dewasa, dan hanya bila jenis kelamin tercatat: rumusnya
     * memang berbeda untuk laki-laki dan perempuan.
     */
    public function idealWeightKg(): ?float
    {
        if ($this->height_cm === null || ! $this->isAdult() || $this->sex === null) {
            return null;
        }

        $tinggi = (float) $this->height_cm;

        if ($tinggi <= 100) {
            return null;
        }

        $dasar = $tinggi - 100;
        $pengurang = $this->sex === 'L' ? 0.10 : 0.15;

        return round($dasar - ($dasar * $pengurang), 1);
    }

    /**
     * Indeks antropometri anak yang BELUM BISA DIHITUNG di sini.
     *
     * BB/U, TB/U, BB/TB, dan LLA/U adalah z-score terhadap tabel standar
     * pertumbuhan WHO. Tabelnya tidak ada di repositori ini, dan
     * mengarangnya menghasilkan angka yang tampak resmi tapi salah pada
     * penilaian yang menentukan apakah seorang anak dinyatakan gizi
     * buruk. Metode ini menyebutkan apa yang belum tersedia alih-alih
     * mengembalikan angka yang tidak bisa dipertanggungjawabkan.
     *
     * @return array<int, string>
     */
    public function pendingChildIndices(): array
    {
        if ($this->isAdult()) {
            return [];
        }

        return ['BB/U', 'TB/U', 'BB/TB', 'LLA/U'];
    }
}
