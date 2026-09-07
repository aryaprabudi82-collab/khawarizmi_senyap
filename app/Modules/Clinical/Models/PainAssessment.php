<?php

namespace App\Modules\Clinical\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Penilaian nyeri.
 *
 * ALAT UKURNYA IKUT DICATAT. Khanza hanya menyimpan angka 0-10 tanpa
 * menyebut alatnya, sehingga skor FLACC pada bayi dan skor NRS pada
 * dewasa terbaca sebanding — padahal bukan.
 */
class PainAssessment extends Model
{
    use SoftDeletes;

    protected $table = 'clinical.pain_assessments';

    protected $guarded = ['id'];

    public const TIDAK_ADA = 'tidak-ada';

    public const AKUT = 'akut';

    public const KRONIS = 'kronis';

    public const JENIS = [
        self::TIDAK_ADA => 'Tidak ada nyeri',
        self::AKUT => 'Nyeri akut',
        self::KRONIS => 'Nyeri kronis',
    ];

    /**
     * Alat ukur nyeri berikut rentangnya.
     *
     * Rentangnya berbeda-beda, dan itulah sebabnya alat ukurnya harus
     * ikut tercatat: skor 3 tidak berarti sama pada semuanya.
     */
    public const SKALA = [
        'nrs' => ['label' => 'Numeric Rating Scale', 'min' => 0, 'max' => 10, 'untuk' => 'Dewasa yang bisa menyebut angkanya'],
        'wong-baker' => ['label' => 'Wong-Baker FACES', 'min' => 0, 'max' => 10, 'untuk' => 'Anak yang sudah bisa menunjuk gambar'],
        'flacc' => ['label' => 'FLACC', 'min' => 0, 'max' => 10, 'untuk' => 'Bayi dan anak prabicara'],
        'cpot' => ['label' => 'CPOT', 'min' => 0, 'max' => 8, 'untuk' => 'Pasien kritis yang tidak bisa melaporkan nyerinya'],
        'bps' => ['label' => 'Behavioral Pain Scale', 'min' => 3, 'max' => 12, 'untuk' => 'Pasien terventilasi'],
    ];

    public const PEREDA = [
        'istirahat' => 'Istirahat',
        'perubahan-posisi' => 'Perubahan posisi',
        'kompres-hangat' => 'Kompres hangat',
        'kompres-dingin' => 'Kompres dingin',
        'relaksasi-napas' => 'Relaksasi napas dalam',
        'distraksi' => 'Distraksi (musik, bacaan, percakapan)',
        'pijat' => 'Pijat ringan',
        'minum-obat' => 'Minum obat',
        'tidak-ada' => 'Tidak ada yang meredakan',
    ];

    protected function casts(): array
    {
        return [
            'assessed_at' => 'datetime',
            'score' => 'integer',
            'radiates' => 'boolean',
            'relieved_by' => 'array',
        ];
    }

    public function interventions(): HasMany
    {
        return $this->hasMany(PainIntervention::class, 'assessment_id')->orderBy('given_at');
    }

    public function evaluatedIntervention(): BelongsTo
    {
        return $this->belongsTo(PainIntervention::class, 'evaluates_intervention_id');
    }

    /**
     * Skor sebagai pecahan dari nilai maksimum alat ukurnya.
     *
     * Inilah satu-satunya cara membandingkan skor lintas alat ukur, dan
     * satu-satunya alasan alat ukurnya perlu tercatat.
     */
    public function normalisedScore(): ?float
    {
        $skala = self::SKALA[$this->scale_type] ?? null;

        if ($skala === null) {
            return null;
        }

        $rentang = $skala['max'] - $skala['min'];

        return $rentang <= 0 ? null : round(($this->score - $skala['min']) / $rentang, 3);
    }

    /**
     * Nyeri yang menuntut penanganan segera.
     *
     * Ambang sepertiga rentang alat ukurnya, bukan angka mutlak —
     * memakai "4 ke atas" pada CPOT yang berhenti di 8 berarti ambang
     * yang jauh lebih ketat daripada pada NRS.
     */
    public function needsIntervention(): bool
    {
        $ternormalisasi = $this->normalisedScore();

        return $ternormalisasi !== null && $ternormalisasi >= 0.4;
    }

    public function isPainFree(): bool
    {
        return $this->kind === self::TIDAK_ADA;
    }
}
