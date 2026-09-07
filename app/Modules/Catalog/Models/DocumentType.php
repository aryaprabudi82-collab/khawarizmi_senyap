<?php

namespace App\Modules\Catalog\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Jenis berkas digital rekam medis.
 *
 * KATEGORINYA yang menentukan masa simpan, bukan namanya — lihat
 * catatan migrasi.
 */
class DocumentType extends Model
{
    protected $table = 'catalog.document_types';

    protected $guarded = ['id'];

    public const KATEGORI = [
        'persetujuan' => 'Persetujuan tindakan',
        'ringkasan' => 'Ringkasan & resume',
        'hasil-penunjang' => 'Hasil penunjang dari luar',
        'surat' => 'Surat-menyurat',
        'identitas' => 'Identitas & penjaminan',
        'lainnya' => 'Lainnya',
    ];

    protected function casts(): array
    {
        return [
            'needs_wet_signature' => 'boolean',
            'is_permanent' => 'boolean',
            'is_active' => 'boolean',
        ];
    }
}
