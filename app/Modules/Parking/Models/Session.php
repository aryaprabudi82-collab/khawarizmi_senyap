<?php

namespace App\Modules\Parking\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Session extends Model
{
    protected $table = 'parking.sessions';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'entered_at' => 'datetime',
            'exited_at' => 'datetime',
            'duration_minutes' => 'integer',
            'total_fee' => 'integer',
        ];
    }

    public function rate(): BelongsTo
    {
        return $this->belongsTo(Rate::class);
    }

    public function barcodeCard(): BelongsTo
    {
        return $this->belongsTo(BarcodeCard::class);
    }

    public function isOpen(): bool
    {
        return $this->exited_at === null;
    }

    public function scopeTerbuka(Builder $query): Builder
    {
        return $query->whereNull('exited_at');
    }
}
