<?php

namespace App\Modules\Order\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class TestCatalog extends Model
{
    public const CATEGORY_LAB = 'lab';
    public const CATEGORY_RADIOLOGI = 'radiologi';

    public const RESULT_KUANTITATIF = 'kuantitatif';
    public const RESULT_KUALITATIF = 'kualitatif';
    public const RESULT_NARATIF = 'naratif';

    protected $table = 'orders.test_catalog';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'reference_low' => 'decimal:2',
            'reference_high' => 'decimal:2',
            'price' => 'decimal:2',
            'is_active' => 'boolean',
        ];
    }

    public function scopeSearch(Builder $query, string $term, ?string $category = null): Builder
    {
        $term = trim($term);

        return $query->where('is_active', true)
            ->when($category, fn ($q) => $q->where('category', $category))
            ->when($term !== '', fn ($q) => $q->where(function (Builder $q) use ($term) {
                $q->where('code', 'ilike', $term . '%')
                    ->orWhere('name', 'ilike', '%' . $term . '%');
            }));
    }

    /** Rentang rujukan yang bisa dibaca manusia, untuk ditampilkan di layar. */
    public function referenceDisplay(): ?string
    {
        if ($this->result_type === self::RESULT_KUANTITATIF && $this->reference_low !== null) {
            return $this->reference_low . '-' . $this->reference_high . ($this->unit ? ' ' . $this->unit : '');
        }

        return $this->reference_text;
    }
}
