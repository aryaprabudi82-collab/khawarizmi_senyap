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

    /**
     * Sistem luar yang daftar referensinya kita salin.
     *
     * Kolomnya bernama `payer` karena mekanisme ini lahir untuk penjamin,
     * dan Sisrute (domain L item P) BUKAN penjamin — ia sistem rujukan
     * Kemenkes. Yang menyatukan mereka bukan soal siapa yang membayar,
     * melainkan bentuk datanya: daftar kode milik pihak lain yang kita
     * salin dan segarkan seluruhnya. Nama konstantanya diluruskan di sini;
     * nama kolomnya sengaja dibiarkan, karena mengubahnya menuntut migrasi
     * dan penyesuaian setiap kueri demi perbaikan penamaan semata.
     */
    public const SISTEM = ['bpjs', 'bpjs-apotek', 'hfis', 'inhealth', 'sisrute'];

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
