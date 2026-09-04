<?php

namespace App\Modules\Pharmacy\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PatientDrugReturnItem extends Model
{
    protected $table = 'pharmacy.patient_drug_return_items';

    protected $guarded = ['id'];

    public $timestamps = false;

    public function return(): BelongsTo
    {
        return $this->belongsTo(PatientDrugReturn::class, 'return_id');
    }

    public function drug(): BelongsTo
    {
        return $this->belongsTo(Drug::class);
    }
}
