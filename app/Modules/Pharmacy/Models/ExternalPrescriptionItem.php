<?php

namespace App\Modules\Pharmacy\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ExternalPrescriptionItem extends Model
{
    protected $table = 'pharmacy.external_prescription_items';

    protected $guarded = ['id'];

    public $timestamps = false;

    protected function casts(): array
    {
        return ['unit_price' => 'decimal:2'];
    }

    public function externalPrescription(): BelongsTo
    {
        return $this->belongsTo(ExternalPrescription::class);
    }

    public function drug(): BelongsTo
    {
        return $this->belongsTo(Drug::class);
    }
}
