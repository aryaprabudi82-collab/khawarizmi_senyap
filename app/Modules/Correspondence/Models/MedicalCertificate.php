<?php

namespace App\Modules\Correspondence\Models;

use Illuminate\Database\Eloquent\Model;

class MedicalCertificate extends Model
{
    public const STATUS_DITERBITKAN = 'diterbitkan';
    public const STATUS_DIBATALKAN = 'dibatalkan';

    public const TYPES = [
        'sehat', 'sakit', 'berobat',
        'bebas_narkoba', 'bebas_tbc', 'buta_warna', 'layak_terbang', 'kewaspadaan_kesehatan', 'covid', 'cuti_hamil',
    ];

    protected $table = 'correspondence.medical_certificates';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'valid_from' => 'date',
            'valid_until' => 'date',
            'issued_at' => 'datetime',
        ];
    }
}
