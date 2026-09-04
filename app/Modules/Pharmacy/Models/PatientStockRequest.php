<?php

namespace App\Modules\Pharmacy\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** permintaan_stok_obat_pasien + stok_obat_pasien — sama pola dengan WardStockRequest, tapi terikat registrasi/pasien tertentu. */
class PatientStockRequest extends Model
{
    public const STATUS_DIAJUKAN = 'diajukan';
    public const STATUS_DIKELUARKAN = 'dikeluarkan';
    public const STATUS_DITOLAK = 'ditolak';

    protected $table = 'pharmacy.patient_stock_requests';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['issued_at' => 'datetime'];
    }

    public function items(): HasMany
    {
        return $this->hasMany(PatientStockRequestItem::class, 'request_id');
    }

    public function isPending(): bool
    {
        return $this->status === self::STATUS_DIAJUKAN;
    }
}
