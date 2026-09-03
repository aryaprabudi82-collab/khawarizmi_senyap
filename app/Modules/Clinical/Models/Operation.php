<?php

namespace App\Modules\Clinical\Models;

use Illuminate\Database\Eloquent\Model;

class Operation extends Model
{
    public const ANESTHESIA_TYPES = ['umum', 'lokal', 'regional', 'tanpa'];

    protected $table = 'clinical.operations';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'performed_at' => 'datetime',
        ];
    }
}
