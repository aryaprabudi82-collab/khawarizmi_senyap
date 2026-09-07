<?php

namespace App\Modules\Clinical\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Laporan naratif sebuah tindakan medik — endoskopi, biopsi,
 * kateterisasi, dan sejenisnya.
 *
 * MELEKAT PADA TINDAKANNYA. laporan_tindakan Khanza berkunci no_rawat
 * saja, jadi pasien yang menjalani dua tindakan dalam satu kunjungan
 * punya laporan yang tidak bisa dibedakan milik tindakan yang mana.
 */
class ProcedureReport extends Model
{
    use SoftDeletes;

    protected $table = 'clinical.procedure_reports';

    protected $guarded = ['id'];

    public const DRAF = 'draf';

    public const FINAL = 'final';

    public const DIBATALKAN = 'dibatalkan';

    protected function casts(): array
    {
        return [
            'reported_at' => 'datetime',
            'finalized_at' => 'datetime',
        ];
    }

    public function procedure(): BelongsTo
    {
        return $this->belongsTo(Procedure::class, 'procedure_id');
    }

    public function isEditable(): bool
    {
        return $this->status === self::DRAF;
    }

    /**
     * Diagnosis berubah setelah tindakan.
     *
     * Bukan kesalahan — justru salah satu alasan tindakan dikerjakan.
     * Yang berguna adalah bisa menyebutkannya, karena diagnosis pasca
     * tindakan itulah yang dipakai koder.
     */
    public function diagnosisChanged(): bool
    {
        return $this->pre_procedure_diagnosis !== null
            && $this->post_procedure_diagnosis !== null
            && $this->pre_procedure_diagnosis !== $this->post_procedure_diagnosis;
    }
}
