<?php

namespace App\Modules\Pharmacy\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** pengeluaran_stok_apotek + pengambilan_utd — permintaan stok ruangan/departemen (bukan untuk pasien tertentu). */
class WardStockRequest extends Model
{
    public const STATUS_DIAJUKAN = 'diajukan';
    public const STATUS_DIKELUARKAN = 'dikeluarkan';
    public const STATUS_DITOLAK = 'ditolak';

    protected $table = 'pharmacy.ward_stock_requests';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['issued_at' => 'datetime'];
    }

    public function items(): HasMany
    {
        return $this->hasMany(WardStockRequestItem::class, 'request_id');
    }

    public function isPending(): bool
    {
        return $this->status === self::STATUS_DIAJUKAN;
    }
}
