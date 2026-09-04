<?php

namespace App\Modules\Pharmacy\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Drug extends Model
{
    protected $table = 'pharmacy.drugs';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'requires_prescription' => 'boolean',
            'is_narcotic' => 'boolean',
            'is_psychotropic' => 'boolean',
            'is_high_alert' => 'boolean',
            'is_active' => 'boolean',
            'sell_price' => 'decimal:2',
            'minimum_stock' => 'decimal:2',
        ];
    }

    public function drugCategory(): BelongsTo
    {
        return $this->belongsTo(DrugCategory::class);
    }

    public function drugClass(): BelongsTo
    {
        return $this->belongsTo(DrugClass::class);
    }

    public function manufacturer(): BelongsTo
    {
        return $this->belongsTo(Manufacturer::class);
    }

    public function units(): HasMany
    {
        return $this->hasMany(DrugUnit::class);
    }

    public function scopeSearch(Builder $query, string $term): Builder
    {
        $term = trim($term);

        if ($term === '') {
            return $query;
        }

        return $query->where('is_active', true)->where(function (Builder $q) use ($term) {
            $q->where('code', 'ilike', $term . '%')
                ->orWhere('name', 'ilike', '%' . $term . '%')
                ->orWhere('generic_name', 'ilike', '%' . $term . '%');
        });
    }

    /** Obat yang butuh pengawasan khusus dan wajib dilaporkan per batch. */
    public function isControlled(): bool
    {
        return $this->is_narcotic || $this->is_psychotropic;
    }

    public function label(): string
    {
        return trim($this->name . ' ' . ($this->strength ?? '')) . ' — ' . $this->form;
    }
}
