<?php

namespace App\Modules\Organization\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Penugasan penanggung jawab sebuah unit penunjang, berjangka waktu.
 *
 * `set_pjlab` Khanza menaruh enam dokter PJ dalam satu baris dengan primary
 * key gabungan dari tiga di antaranya — tanpa riwayat sama sekali. Jadi
 * "siapa PJ laboratorium bulan Maret", pertanyaan yang justru ditanyakan
 * saat hasil dipersoalkan atau insiden ditelusuri, tidak punya jawaban.
 */
class UnitSupervisor extends Model
{
    protected $table = 'organization.unit_supervisors';

    protected $guarded = ['id'];

    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class, 'unit_id');
    }

    public function practitioner(): BelongsTo
    {
        return $this->belongsTo(Practitioner::class, 'practitioner_id');
    }

    /** Kosongnya end_date berarti masih menjabat, bukan tidak diketahui. */
    public function masihMenjabat(): bool
    {
        return $this->end_date === null;
    }

    protected function casts(): array
    {
        return [
            'start_date' => 'date',
            'end_date' => 'date',
        ];
    }
}
