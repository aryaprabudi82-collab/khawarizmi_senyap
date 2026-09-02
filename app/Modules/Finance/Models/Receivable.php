<?php

namespace App\Modules\Finance\Models;

use Illuminate\Database\Eloquent\Model;

class Receivable extends Model
{
    public const STATUS_TERBUKA = 'terbuka';
    public const STATUS_TERTAGIH = 'tertagih';

    protected $table = 'finance.receivables';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'opened_at' => 'datetime',
            'collected_at' => 'datetime',
        ];
    }

    public function isCollected(): bool
    {
        return $this->status === self::STATUS_TERTAGIH;
    }
}
