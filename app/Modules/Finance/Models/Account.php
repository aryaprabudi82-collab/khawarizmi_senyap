<?php

namespace App\Modules\Finance\Models;

use Illuminate\Database\Eloquent\Model;

class Account extends Model
{
    public const TYPE_KAS = 'kas';
    public const TYPE_PIUTANG = 'piutang';
    public const TYPE_PENDAPATAN = 'pendapatan';
    public const TYPE_BEBAN = 'beban';
    public const TYPE_UTANG = 'utang';

    protected $table = 'finance.chart_of_accounts';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }
}
