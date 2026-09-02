<?php

namespace App\Modules\Integration\Models;

use Illuminate\Database\Eloquent\Model;

class BpjsSep extends Model
{
    public const STATUS_DIAJUKAN = 'diajukan';
    public const STATUS_TERBIT = 'terbit';
    public const STATUS_GAGAL = 'gagal';
    public const STATUS_BATAL = 'batal';

    public const JENIS_RANAP = '1';
    public const JENIS_RALAN = '2';

    protected $table = 'integration.bpjs_sep';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'request_payload' => 'array',
            'response_payload' => 'array',
            'requested_at' => 'datetime',
            'issued_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    public function isActive(): bool
    {
        return in_array($this->status, [self::STATUS_DIAJUKAN, self::STATUS_TERBIT], true);
    }
}
