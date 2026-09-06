<?php

namespace App\Modules\Integration\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Keikutsertaan pasien pada Program Rujuk Balik BPJS.
 *
 * prb_number berasal dari BPJS, bukan dinomori sendiri.
 *
 * DITOLAK adalah status yang sah, bukan kekosongan. Pasien berhak menolak,
 * dan penolakannya dicatat berikut alasannya supaya "belum ditawarkan"
 * tetap bisa dibedakan dari "sudah ditawarkan dan ditolak".
 */
class PrbEnrollment extends Model
{
    protected $table = 'integration.prb_enrollments';

    protected $guarded = ['id'];

    public const CALON = 'calon';
    public const DITAWARKAN = 'ditawarkan';
    public const TERDAFTAR = 'terdaftar';
    public const DITOLAK = 'ditolak';
    public const SELESAI = 'selesai';
    public const BATAL = 'batal';

    /** Status yang masih menahan slot satu peserta per diagnosis. */
    public const AKTIF = [self::CALON, self::DITAWARKAN, self::TERDAFTAR];

    protected function casts(): array
    {
        return [
            'offered_on' => 'date',
            'enrolled_on' => 'date',
            'valid_until' => 'date',
        ];
    }

    public function pharmacyServices(): HasMany
    {
        return $this->hasMany(BpjsPharmacyService::class, 'prb_enrollment_id');
    }

    /** Surat PRB punya masa berlaku; yang lewat tempo ditolak di apotek. */
    public function isExpired(): bool
    {
        return $this->status === self::TERDAFTAR
            && $this->valid_until !== null
            && $this->valid_until->isPast();
    }
}
