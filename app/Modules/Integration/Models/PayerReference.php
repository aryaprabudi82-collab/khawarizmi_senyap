<?php

namespace App\Modules\Integration\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Satu baris daftar referensi milik penjamin.
 *
 * SALINAN, bukan master: daftar poli dan dokter di sini milik BPJS atau
 * Inhealth. Master kita tetap di konteks organization.
 */
class PayerReference extends Model
{
    protected $table = 'integration.payer_references';

    protected $guarded = ['id'];

    public const PENJAMIN = ['bpjs', 'bpjs-apotek', 'hfis', 'inhealth'];

    protected function casts(): array
    {
        return ['raw' => 'array', 'fetched_at' => 'datetime'];
    }

    /** Umur salinan dalam hari — referensi basi harus terlihat basi. */
    public function ageInDays(): int
    {
        return (int) $this->fetched_at->diffInDays(now());
    }
}
