<?php

namespace App\Modules\Integration\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Surat Jaminan Pelayanan (SJP) Inhealth — padanan SEP di sisi BPJS.
 *
 * sjp_number berasal dari Inhealth, bukan dinomori sendiri: nomor karangan
 * tidak akan dikenali saat tagihannya diajukan.
 */
class InhealthGuarantee extends Model
{
    protected $table = 'integration.inhealth_guarantees';

    protected $guarded = ['id'];

    public const DIAJUKAN = 'diajukan';
    public const TERBIT = 'terbit';
    public const GAGAL = 'gagal';
    public const BATAL = 'batal';

    /** Status yang masih menahan slot satu SJP per kunjungan. */
    public const AKTIF = [self::DIAJUKAN, self::TERBIT];

    public const TAGIHAN_DIAJUKAN = 'diajukan';
    public const TAGIHAN_DITERIMA = 'diterima';
    public const TAGIHAN_DITOLAK = 'ditolak';

    protected function casts(): array
    {
        return [
            'request_payload' => 'array',
            'response_payload' => 'array',
            'billing_items' => 'array',
            'billing_response' => 'array',
            'billed_amount' => 'decimal:2',
            'issued_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'billed_at' => 'datetime',
            'requested_at' => 'datetime',
        ];
    }

    public function isBilled(): bool
    {
        return $this->billing_status !== null;
    }
}
