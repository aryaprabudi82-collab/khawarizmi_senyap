<?php

namespace App\Modules\Clinical\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Diagnosis extends Model
{
    use SoftDeletes;

    public const RANK_UTAMA = 'utama';
    public const RANK_SEKUNDER = 'sekunder';
    public const RANK_KOMPLIKASI = 'komplikasi';

    protected $table = 'clinical.diagnoses';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['diagnosed_at' => 'datetime'];
    }
}
