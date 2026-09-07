<?php

namespace App\Modules\Integration\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Resep yang dikirim ke Apotek Online BPJS.
 *
 * Resep induk punya iteration_index = 0. Penebusan lanjutan menunjuk
 * induknya — satu resep yang ditebus berkali-kali, bukan resep baru tiap
 * bulan.
 */
class BpjsApotekPrescription extends Model
{
    protected $table = 'integration.bpjs_apotek_prescriptions';

    protected $guarded = ['id'];

    public const TERKIRIM = 'terkirim';
    public const GAGAL = 'gagal';
    public const BATAL = 'batal';

    /** Batas iterasi resep PRB menurut ketentuan BPJS. */
    public const MAKS_ITERASI = 3;

    protected function casts(): array
    {
        return [
            'prescribed_on' => 'date',
            'is_iterative' => 'boolean',
            'total_amount' => 'decimal:2',
            'items' => 'array',
            'raw_response' => 'array',
        ];
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function iterations(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    public function isParent(): bool
    {
        return $this->iteration_index === 0;
    }

    /** Sisa jatah penebusan lanjutan resep ini. */
    public function remainingIterations(): int
    {
        if (! $this->isParent() || ! $this->is_iterative) {
            return 0;
        }

        $terpakai = $this->iterations()->where('status', '<>', self::BATAL)->count();

        return max(0, $this->iteration_allowed - $terpakai);
    }
}
