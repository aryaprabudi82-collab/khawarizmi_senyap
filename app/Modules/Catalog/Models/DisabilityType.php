<?php

namespace App\Modules\Catalog\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Jenis cacat fisik / ragam disabilitas.
 *
 * Kategorinya mengikuti pengelompokan ragam penyandang disabilitas
 * dalam UU 8/2016 — fisik, intelektual, mental, sensorik, dan ganda —
 * bukan dikarang. cacat_fisik Khanza hanya punya nama tanpa kategori.
 */
class DisabilityType extends Model
{
    protected $table = 'catalog.disability_types';

    protected $guarded = ['id'];

    public const KATEGORI = [
        'fisik' => 'Disabilitas fisik',
        'sensorik' => 'Disabilitas sensorik',
        'intelektual' => 'Disabilitas intelektual',
        'mental' => 'Disabilitas mental',
        'ganda' => 'Disabilitas ganda',
        'lainnya' => 'Lainnya',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }
}
