<?php

namespace App\Modules\Clinical\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Berkas digital yang menempel pada rekam medis — surat rujukan dari
 * luar, hasil penunjang fasilitas lain, persetujuan bertanda tangan
 * basah yang dipindai.
 *
 * Khanza hanya menyimpan no_rawat, kode, dan lokasi_file. Di sini ikut
 * tercatat siapa mengunggah, kapan, dan SIDIK BERKASNYA — supaya
 * penggantian berkas di jalur yang sama bisa ketahuan.
 */
class MedicalRecordDocument extends Model
{
    use SoftDeletes;

    protected $table = 'clinical.medical_record_documents';

    protected $guarded = ['id'];

    public const AKTIF = 'aktif';

    public const DIGANTI = 'diganti';

    public const DIBATALKAN = 'dibatalkan';

    protected function casts(): array
    {
        return [
            'size_bytes' => 'integer',
            'uploaded_at' => 'datetime',
        ];
    }

    public function supersededBy(): BelongsTo
    {
        return $this->belongsTo(self::class, 'superseded_by_id');
    }

    public function isActive(): bool
    {
        return $this->status === self::AKTIF;
    }

    /**
     * Apakah isi berkas masih sama dengan yang diunggah.
     *
     * Dipanggil dengan sidik berkas yang dibaca ulang dari penyimpanan.
     * Inilah kegunaan checksum di sini: bukan mengunci berkasnya —
     * penyimpanan tetap bisa ditimpa — melainkan membuat penimpaan
     * itu KETAHUAN.
     */
    public function matches(string $checksum): bool
    {
        return hash_equals($this->checksum_sha256, strtolower($checksum));
    }
}
