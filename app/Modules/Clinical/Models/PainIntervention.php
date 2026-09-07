<?php

namespace App\Modules\Clinical\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Penanganan nyeri — tabel yang tidak pernah dibuat Khanza meski kode
 * izinnya ada dua (intervensi_nyeri_farmakologi dan _nonfarmakologi).
 *
 * Tanpa tabel ini lingkaran akreditasi nilai -> tangani -> nilai ulang
 * tidak bisa ditutup: penilaian ulang yang skornya tetap tinggi tidak
 * bisa dijawab pertanyaan "memangnya sudah diapakan?".
 */
class PainIntervention extends Model
{
    use SoftDeletes;

    protected $table = 'clinical.pain_interventions';

    protected $guarded = ['id'];

    public const FARMAKOLOGI = 'farmakologi';

    public const NONFARMAKOLOGI = 'nonfarmakologi';

    public const JENIS = [
        self::FARMAKOLOGI => 'Farmakologi',
        self::NONFARMAKOLOGI => 'Nonfarmakologi',
    ];

    /**
     * Tenggat penilaian ulang setelah intervensi, dalam menit.
     *
     * Obat suntik bekerja lebih cepat daripada obat minum, dan tindakan
     * nonfarmakologi dinilai setelah pasien sempat merasakannya. Angka
     * ini dipakai untuk MENAGIH penilaian ulang, bukan untuk menolak
     * apa pun.
     */
    public const TENGGAT_EVALUASI_MENIT = [
        self::FARMAKOLOGI => 60,
        self::NONFARMAKOLOGI => 60,
    ];

    protected function casts(): array
    {
        return [
            'given_at' => 'datetime',
        ];
    }

    public function assessment(): BelongsTo
    {
        return $this->belongsTo(PainAssessment::class, 'assessment_id');
    }

    /** Penilaian ulang yang mengevaluasi intervensi ini, bila sudah ada. */
    public function evaluation(): HasOne
    {
        return $this->hasOne(PainAssessment::class, 'evaluates_intervention_id');
    }

    public function isPharmacological(): bool
    {
        return $this->kind === self::FARMAKOLOGI;
    }

    /**
     * Sudah lewat tenggat penilaian ulang dan belum dinilai ulang.
     *
     * Dihitung, tidak disimpan: begitu penilaian ulangnya dicatat,
     * jawabannya berubah dengan sendirinya.
     */
    public function isAwaitingEvaluation(): bool
    {
        if ($this->evaluation()->exists()) {
            return false;
        }

        $tenggat = self::TENGGAT_EVALUASI_MENIT[$this->kind] ?? 60;

        return $this->given_at->addMinutes($tenggat)->isPast();
    }
}
