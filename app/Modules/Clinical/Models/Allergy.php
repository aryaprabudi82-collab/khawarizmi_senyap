<?php

namespace App\Modules\Clinical\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Allergy extends Model
{
    use SoftDeletes;

    public const STATUS_AKTIF = 'aktif';
    public const STATUS_TIDAK_AKTIF = 'tidak-aktif';
    public const STATUS_DISANGKAL = 'disangkal';

    protected $table = 'clinical.allergies';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['recorded_at' => 'datetime'];
    }

    public function scopeAktif(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_AKTIF);
    }
}
