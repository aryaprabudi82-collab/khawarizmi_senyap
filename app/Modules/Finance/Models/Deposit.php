<?php

namespace App\Modules\Finance\Models;

use Illuminate\Database\Eloquent\Model;

class Deposit extends Model
{
    public const STATUS_AKTIF = 'aktif';
    public const STATUS_TERPAKAI = 'terpakai';

    protected $table = 'finance.deposits';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'deposited_at' => 'datetime',
            'applied_at' => 'datetime',
        ];
    }
}
