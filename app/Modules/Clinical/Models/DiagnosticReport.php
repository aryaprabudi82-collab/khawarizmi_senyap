<?php

namespace App\Modules\Clinical\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Hasil pemeriksaan penunjang khusus: EKG, USG, ECHO, endoskopi, OCT,
 * treadmill, ESWL, uji fungsi KFR.
 *
 * PEMERIKSA berbeda dari PENCATAT: dokter yang membaca EKG bertanggung
 * jawab atas tafsirannya, yang mengetiknya bisa orang lain.
 */
class DiagnosticReport extends Model
{
    use SoftDeletes;

    protected $table = 'clinical.diagnostic_reports';

    protected $guarded = ['id'];

    public const DRAF = 'draf';
    public const FINAL = 'final';
    public const DIBATALKAN = 'dibatalkan';

    protected function casts(): array
    {
        return [
            'findings' => 'array',
            'template_approved' => 'boolean',
            'performed_at' => 'datetime',
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
}
