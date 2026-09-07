<?php

namespace App\Modules\Integration\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Satu rujukan Sisrute, keluar maupun masuk.
 *
 * Arah KELUAR menunjuk rujukan yang isinya sudah tersimpan di konteks
 * encounter — yang dicatat di sini cuma pengiriman dan jawabannya. Arah
 * MASUK memang data baru: permintaan dari rumah sakit lain yang belum
 * punya padanan apa pun dalam catatan kita.
 */
class SisruteReferral extends Model
{
    protected $table = 'integration.sisrute_referrals';

    protected $guarded = ['id'];

    public const KELUAR = 'keluar';
    public const MASUK = 'masuk';

    public const DIAJUKAN = 'diajukan';
    public const DITERIMA = 'diterima';
    public const DITOLAK = 'ditolak';
    public const DIBATALKAN = 'dibatalkan';
    public const TIBA = 'tiba';
    public const GAGAL = 'gagal';

    /** Status yang masih menahan slot satu pengajuan per rujukan keluar. */
    public const BERJALAN = [self::DIAJUKAN, self::DITERIMA];

    protected function casts(): array
    {
        return [
            'patient_birth_date' => 'date',
            'requested_at' => 'datetime',
            'responded_at' => 'datetime',
            'raw_response' => 'array',
        ];
    }

    public function isIncoming(): bool
    {
        return $this->direction === self::MASUK;
    }

    /**
     * Sudah disanggupi tapi pasiennya belum tiba.
     *
     * Tempat yang disanggupi menahan kapasitas nyata; yang menggantung
     * terlalu lama perlu ditanyakan, bukan dibiarkan.
     */
    public function isAwaitingArrival(): bool
    {
        return $this->direction === self::MASUK
            && $this->status === self::DITERIMA
            && $this->registration_id === null;
    }
}
