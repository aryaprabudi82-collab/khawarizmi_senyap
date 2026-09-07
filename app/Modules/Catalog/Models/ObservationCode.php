<?php

namespace App\Modules\Catalog\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Satu jenis pengukuran yang bisa dicatat pada pasien.
 *
 * Rentang di sini adalah rentang BAWAAN. Yang berlaku saat mengukur adalah
 * rentang panelnya bila panel itu menyebut sendiri — laju napas normal
 * berbeda antara neonatus dan dewasa.
 */
class ObservationCode extends Model
{
    protected $table = 'catalog.observation_codes';

    protected $guarded = ['id'];

    public const NUMERIC = 'numeric';
    public const TEXT = 'text';

    protected function casts(): array
    {
        return [
            'reference_low' => 'decimal:2',
            'reference_high' => 'decimal:2',
            'is_active' => 'boolean',
        ];
    }
}
