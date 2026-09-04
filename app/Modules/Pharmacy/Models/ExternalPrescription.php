<?php

namespace App\Modules\Pharmacy\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** resep_luar — resep ditulis di luar RS, dilayani sebagai walk-in. */
class ExternalPrescription extends Model
{
    public const STATUS_DITERIMA = 'diterima';
    public const STATUS_DISERAHKAN = 'diserahkan';
    public const STATUS_DIBATALKAN = 'dibatalkan';

    protected $table = 'pharmacy.external_prescriptions';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'issued_date' => 'date',
            'dispensed_at' => 'datetime',
            'total_amount' => 'decimal:2',
        ];
    }

    public function items(): HasMany
    {
        return $this->hasMany(ExternalPrescriptionItem::class, 'external_prescription_id');
    }
}
