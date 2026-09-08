<?php

namespace App\Modules\Correspondence\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Klasifikasi arsip: KODE PERIHAL, bukan sifat surat.
 *
 * Yang selama ini bernama `classification` pada tabel surat isinya sifat
 * (biasa/penting/rahasia/segera). Klasifikasi dalam arti kearsipan adalah
 * pola kode perihal yang disusun tiap instansi mengikuti pedoman ANRI, dan
 * justru itulah yang dipakai menemukan kembali surat bertahun kemudian.
 *
 * Daftarnya SENGAJA lahir kosong: pola klasifikasi arsip adalah keputusan
 * RSP UI, dan menebaknya berarti mengarang kode yang akan tercetak pada
 * nomor surat resmi selama bertahun-tahun.
 */
class LetterClassification extends Model
{
    protected $table = 'correspondence.letter_classifications';

    protected $guarded = ['id'];

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }
}
