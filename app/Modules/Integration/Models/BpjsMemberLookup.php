<?php

namespace App\Modules\Integration\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Satu hasil pencarian tentang peserta di VClaim.
 *
 * Isinya SALINAN JAWABAN BPJS, bukan kebenaran kita sendiri — tidak pernah
 * dipakai menggantikan data pasien di konteks identity.
 */
class BpjsMemberLookup extends Model
{
    protected $table = 'integration.bpjs_member_lookups';

    protected $guarded = ['id'];

    public const NIK = 'nik';
    public const SKDP = 'skdp';
    public const HISTORI = 'histori';
    public const FINGERPRINT = 'fingerprint';

    public const JENIS = [self::NIK, self::SKDP, self::HISTORI, self::FINGERPRINT];

    protected function casts(): array
    {
        return [
            'found' => 'boolean',
            'period_from' => 'date',
            'period_until' => 'date',
            'raw_response' => 'array',
            'checked_at' => 'datetime',
        ];
    }

    /** Baris-baris jawaban, untuk pencarian yang mengembalikan daftar. */
    public function rows(): array
    {
        return $this->raw_response['rows'] ?? [];
    }
}
