<?php

namespace App\Modules\Pharmacy\Models;

use Illuminate\Database\Eloquent\Model;

class PrescriptionReview extends Model
{
    public $timestamps = false;

    protected $table = 'pharmacy.prescription_reviews';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'findings' => 'array',
            'reviewed_at' => 'datetime',
        ];
    }
}
