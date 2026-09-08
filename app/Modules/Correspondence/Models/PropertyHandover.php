<?php

namespace App\Modules\Correspondence\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class PropertyHandover extends Model
{
    public const JENIS_BARANG = 'barang-pasien';

    public const JENIS_ANGGOTA_TUBUH = 'anggota-tubuh';

    public const JENIS = [self::JENIS_BARANG, self::JENIS_ANGGOTA_TUBUH];

    public const ARAH_DITITIPKAN = 'dititipkan';

    public const ARAH_DISERAHKAN = 'diserahkan';

    public const HUBUNGAN = [
        'diri-sendiri', 'suami', 'istri', 'ayah', 'ibu',
        'anak', 'saudara-kandung', 'pengampu', 'lainnya',
    ];

    protected $table = 'correspondence.property_handovers';

    protected $guarded = ['id'];

    /** Penyerahan yang melunasi titipan ini, bila sudah ada. */
    public function settlement(): HasOne
    {
        return $this->hasOne(self::class, 'settles_handover_id');
    }

    /** Titipan yang dilunasi oleh penyerahan ini. */
    public function settles(): BelongsTo
    {
        return $this->belongsTo(self::class, 'settles_handover_id');
    }

    public function settled(): bool
    {
        return $this->settlement()->exists();
    }

    protected function casts(): array
    {
        return ['occurred_at' => 'datetime'];
    }
}
