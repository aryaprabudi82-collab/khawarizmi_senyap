<?php

namespace App\Modules\Finance\Models;

use Illuminate\Database\Eloquent\Model;

class CashCategory extends Model
{
    protected $table = 'finance.cash_categories';

    protected $guarded = ['id'];

    public const MASUK = 'masuk';
    public const KELUAR = 'keluar';

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }
}
