<?php

namespace App\Modules\Integration\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Rujukan keluar dari rumah sakit ini ke faskes lain.
 *
 * Rujukan khusus (penyakit kronis dengan aturan berbeda) dibedakan kolom
 * is_special, bukan tabel tersendiri — yang berbeda jenisnya, bukan bentuk
 * datanya.
 */
class BpjsOutgoingReferral extends Model
{
    protected $table = 'integration.bpjs_outgoing_referrals';

    protected $guarded = ['id'];

    public const TERBIT = 'terbit';
    public const GAGAL = 'gagal';

    protected function casts(): array
    {
        return ['referral_date' => 'date', 'is_special' => 'boolean'];
    }
}
