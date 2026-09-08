<?php

namespace App\Modules\Correspondence\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Satu butir penjelasan pada sebuah persetujuan.
 *
 * `confirmed` sengaja boleh kosong. Null berarti butir ini belum sempat
 * dijelaskan — keadaan yang berbeda dari "sudah dijelaskan tapi pasien
 * menyatakan belum paham" (false). Menyamakan keduanya menghapus jejak
 * pasien yang bilang tidak paham lalu tetap diminta menandatangani.
 */
class ConsentInformationItem extends Model
{
    protected $table = 'correspondence.consent_information_items';

    protected $guarded = ['id'];

    public function consent(): BelongsTo
    {
        return $this->belongsTo(PatientConsent::class, 'consent_id');
    }

    /** Butir yang belum sempat dijelaskan sama sekali. */
    public function belumDitanyakan(): bool
    {
        return $this->confirmed === null;
    }

    protected function casts(): array
    {
        return [
            'is_required' => 'boolean',
            'confirmed' => 'boolean',
        ];
    }
}
