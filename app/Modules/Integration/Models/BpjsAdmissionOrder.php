<?php

namespace App\Modules\Integration\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Surat Perintah Rawat Inap (SPRI) BPJS.
 *
 * order_number berasal dari BPJS, bukan dinomori sendiri — nomor karangan
 * tidak akan dikenali saat SEP rawat inapnya diterbitkan.
 */
class BpjsAdmissionOrder extends Model
{
    protected $table = 'integration.bpjs_admission_orders';

    protected $guarded = ['id'];

    public const TERBIT = 'terbit';
    public const GAGAL = 'gagal';
    public const BATAL = 'batal';
    public const TERPAKAI = 'terpakai';

    /** Status yang masih menahan slot satu surat per kunjungan. */
    public const AKTIF = [self::TERBIT, self::TERPAKAI];

    protected function casts(): array
    {
        return [
            'planned_date' => 'date',
            'raw_response' => 'array',
            'cancelled_at' => 'datetime',
        ];
    }

    /**
     * Surat yang tanggal rencananya sudah lewat tanpa pernah dipakai.
     *
     * Bukan kesalahan, tapi perlu terlihat: pasien yang batal masuk bangsal
     * meninggalkan surat menggantung yang menahan penerbitan surat baru.
     */
    public function isStale(): bool
    {
        return $this->status === self::TERBIT && $this->planned_date->isPast();
    }
}
