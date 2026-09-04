<?php

namespace App\Modules\Pharmacy\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** pengajuan_barang_medis — pola sama dengan Inventory\Models\Requisition (non-medis). */
class DrugRequisition extends Model
{
    public const STATUS_DIAJUKAN = 'diajukan';
    public const STATUS_DISETUJUI = 'disetujui';
    public const STATUS_DITOLAK = 'ditolak';
    public const STATUS_SELESAI = 'selesai';

    protected $table = 'pharmacy.drug_requisitions';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['decided_at' => 'datetime'];
    }

    public function items(): HasMany
    {
        return $this->hasMany(DrugRequisitionItem::class, 'requisition_id');
    }

    public function isPending(): bool
    {
        return $this->status === self::STATUS_DIAJUKAN;
    }
}
