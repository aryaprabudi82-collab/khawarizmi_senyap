<?php

namespace App\Modules\Finance\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Padanan tabel akun_bayar Khanza (nama_bayar -> kd_rek), diperluas ke jenis biaya juga. */
class AccountMapping extends Model
{
    public const KIND_CARA_BAYAR = 'cara-bayar';
    public const KIND_SUMBER = 'sumber-pendapatan';

    protected $table = 'finance.account_mappings';

    protected $guarded = ['id'];

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'account_id');
    }
}
