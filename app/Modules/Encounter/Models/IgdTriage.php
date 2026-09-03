<?php

namespace App\Modules\Encounter\Models;

use Illuminate\Database\Eloquent\Model;

class IgdTriage extends Model
{
    public const LEVELS = ['merah', 'kuning', 'hijau', 'hitam'];

    /** Urutan prioritas pelayanan — dipakai mengurutkan daftar IGD. */
    public const PRIORITY = ['merah' => 1, 'kuning' => 2, 'hijau' => 3, 'hitam' => 4];

    protected $table = 'encounter.igd_triages';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['triaged_at' => 'datetime'];
    }
}
