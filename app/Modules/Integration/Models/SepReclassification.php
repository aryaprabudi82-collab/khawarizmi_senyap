<?php

namespace App\Modules\Integration\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Satu perubahan klasifikasi SEP.
 *
 * Klasifikasi lama DISIMPAN, bukan ditimpa: reklasifikasi mengubah apa
 * yang ditagihkan ke negara, dan perubahan tagihan yang tidak
 * meninggalkan jejak tidak bisa dibedakan dari kecurangan.
 */
class SepReclassification extends Model
{
    protected $table = 'integration.sep_reclassifications';

    protected $guarded = ['id'];

    public const DIAJUKAN = 'diajukan';
    public const DITERIMA = 'diterima';
    public const GAGAL = 'gagal';

    protected function casts(): array
    {
        return ['raw_response' => 'array'];
    }

    public function sep(): BelongsTo
    {
        return $this->belongsTo(BpjsSep::class, 'sep_id');
    }
}
