<?php

namespace App\Modules\Clinical\Models;

use Illuminate\Database\Eloquent\Model;

/** tindakan_ralan — lihat catatan migrasi untuk alasan tidak bisa dihapus setelah dicatat. */
class Procedure extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'clinical.procedures';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:2',
            'unit_price' => 'decimal:2',
            'amount' => 'decimal:2',
            'performed_at' => 'datetime',
        ];
    }
}
