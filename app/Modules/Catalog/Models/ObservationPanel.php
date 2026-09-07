<?php

namespace App\Modules\Catalog\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Kumpulan pengukuran yang muncul di satu layar observasi.
 *
 * Setiap catatan_observasi_* Khanza jadi satu panel di sini — unit baru
 * cukup menambah baris, bukan menuntut migrasi.
 */
class ObservationPanel extends Model
{
    protected $table = 'catalog.observation_panels';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function items(): HasMany
    {
        return $this->hasMany(ObservationPanelItem::class, 'observation_panel_id');
    }
}
