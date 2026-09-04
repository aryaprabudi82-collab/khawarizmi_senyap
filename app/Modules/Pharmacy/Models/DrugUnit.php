<?php

namespace App\Modules\Pharmacy\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** konversi_satuan — satu baris per satuan tambahan yang dimiliki sebuah obat, relatif terhadap drugs.unit (satuan dasar, conversion_to_base tersirat 1). */
class DrugUnit extends Model
{
    protected $table = 'pharmacy.drug_units';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'conversion_to_base' => 'decimal:4',
            'is_purchase_unit' => 'boolean',
            'is_dispense_unit' => 'boolean',
        ];
    }

    public function drug(): BelongsTo
    {
        return $this->belongsTo(Drug::class);
    }

    public function unit(): BelongsTo
    {
        return $this->belongsTo(MeasureUnit::class, 'unit_id');
    }
}
