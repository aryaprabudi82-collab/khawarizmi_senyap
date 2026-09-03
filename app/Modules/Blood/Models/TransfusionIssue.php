<?php

namespace App\Modules\Blood\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TransfusionIssue extends Model
{
    protected $table = 'blood.transfusion_issues';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['issued_at' => 'datetime'];
    }

    public function bloodUnit(): BelongsTo
    {
        return $this->belongsTo(BloodUnit::class);
    }
}
