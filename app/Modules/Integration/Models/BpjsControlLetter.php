<?php

namespace App\Modules\Integration\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Surat kontrol BPJS.
 *
 * letter_number berasal dari BPJS, bukan dinomori sendiri — nomor karangan
 * tidak akan dikenali saat pasien datang kontrol.
 */
class BpjsControlLetter extends Model
{
    protected $table = 'integration.bpjs_control_letters';

    protected $guarded = ['id'];

    public const TERBIT = 'terbit';
    public const GAGAL = 'gagal';
    public const BATAL = 'batal';

    protected function casts(): array
    {
        return ['planned_date' => 'date', 'cancelled_at' => 'datetime'];
    }
}
