<?php

namespace App\Modules\Pharmacy\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** retur_obat_ranap — obat rawat inap tak terpakai dikembalikan. */
class PatientDrugReturn extends Model
{
    protected $table = 'pharmacy.patient_drug_returns';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['returned_at' => 'datetime'];
    }

    public function items(): HasMany
    {
        return $this->hasMany(PatientDrugReturnItem::class, 'return_id');
    }
}
