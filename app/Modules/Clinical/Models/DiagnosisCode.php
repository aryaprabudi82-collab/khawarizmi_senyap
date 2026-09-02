<?php

namespace App\Modules\Clinical\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class DiagnosisCode extends Model
{
    protected $table = 'clinical.diagnosis_codes';
    protected $primaryKey = 'code';
    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = ['code', 'display', 'display_id', 'chapter', 'is_active'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    /** Pencarian sambil mengetik, ditopang index trigram. */
    public function scopeSearch(Builder $query, string $term): Builder
    {
        $term = trim($term);

        if ($term === '') {
            return $query;
        }

        return $query->where('is_active', true)->where(function (Builder $q) use ($term) {
            $q->where('code', 'ilike', $term . '%')
                ->orWhere('display', 'ilike', '%' . $term . '%')
                ->orWhere('display_id', 'ilike', '%' . $term . '%');
        });
    }

    public function label(): string
    {
        return $this->code . ' — ' . ($this->display_id ?: $this->display);
    }
}
