<?php

namespace App\Modules\Pharmacy\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DrugRequisitionItem extends Model
{
    protected $table = 'pharmacy.drug_requisition_items';

    protected $guarded = ['id'];

    public $timestamps = false;

    public function requisition(): BelongsTo
    {
        return $this->belongsTo(DrugRequisition::class, 'requisition_id');
    }

    public function drug(): BelongsTo
    {
        return $this->belongsTo(Drug::class);
    }
}
