<?php

namespace App\Modules\Correspondence\Models;

use Illuminate\Database\Eloquent\Model;

class PatientConsent extends Model
{
    public const STATUS_AKTIF = 'aktif';
    public const STATUS_DIBATALKAN = 'dibatalkan';

    public const TYPES = [
        'tindakan', 'penolakan-anjuran-medis', 'resusitasi', 'umum',
        'pemeriksaan-hiv', 'penundaan-pelayanan', 'rawat-inap', 'pulang-permintaan-sendiri',
    ];

    protected $table = 'correspondence.patient_consents';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['signed_at' => 'datetime'];
    }
}
