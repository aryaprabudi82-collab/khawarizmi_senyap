<?php

namespace App\Modules\Clinical\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Resume medis — ringkasan satu episode perawatan yang dibawa pasien
 * pulang dan dibaca fasilitas berikutnya.
 *
 * Diagnosis, prosedur, dan obat pulangnya SALINAN BEKU dari rekam
 * medisnya sendiri, bukan ketikan kedua. Lihat catatan migrasi.
 */
class DischargeSummary extends Model
{
    use SoftDeletes;

    protected $table = 'clinical.discharge_summaries';

    protected $guarded = ['id'];

    public const DRAF = 'draf';

    public const FINAL = 'final';

    public const DIBATALKAN = 'dibatalkan';

    public const HIDUP = 'hidup';

    public const MENINGGAL = 'meninggal';

    protected function casts(): array
    {
        return [
            'diagnoses' => 'array',
            'procedures' => 'array',
            'discharge_medications' => 'array',
            'admitted_at' => 'datetime',
            'discharged_at' => 'datetime',
            'control_on' => 'date',
            'finalized_at' => 'datetime',
        ];
    }

    public function isFinal(): bool
    {
        return $this->status === self::FINAL;
    }

    public function isEditable(): bool
    {
        return $this->status === self::DRAF;
    }

    /** Diagnosis utama yang dibekukan, bila ada. */
    public function primaryDiagnosis(): ?array
    {
        foreach ($this->diagnoses ?? [] as $diagnosis) {
            if (($diagnosis['rank'] ?? null) === 'utama') {
                return $diagnosis;
            }
        }

        return null;
    }
}
