<?php

namespace App\Modules\Platform\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Satu perubahan pengaturan: nilai lama, nilai baru, siapa, dan sejak kapan.
 *
 * TIDAK ADA PADANANNYA DI KHANZA. Seluruh tabel `set_*` hanya menyimpan
 * nilai berjalan, jadi tarif embalase yang dipakai menagih resep bulan lalu
 * tidak bisa direkonstruksi — dan tagihan yang tidak bisa direkonstruksi
 * tidak bisa dibantah maupun dibenarkan.
 */
class SettingRevision extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'platform.setting_revisions';

    protected $guarded = ['id'];

    public function setting(): BelongsTo
    {
        return $this->belongsTo(Setting::class, 'setting_id');
    }

    protected function casts(): array
    {
        return [
            'effective_from' => 'date',
            'created_at' => 'datetime',
        ];
    }
}
