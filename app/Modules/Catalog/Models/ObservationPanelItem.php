<?php

namespace App\Modules\Catalog\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Satu pengukuran di dalam sebuah panel, berikut rentang rujukan khusus
 * panel itu bila ada.
 *
 * Rentang kosong berarti "pakai rentang bawaan kodenya" — bukan berarti
 * pengukuran ini tidak punya rentang.
 */
class ObservationPanelItem extends Model
{
    protected $table = 'catalog.observation_panel_items';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'reference_low' => 'decimal:2',
            'reference_high' => 'decimal:2',
            'is_required' => 'boolean',
        ];
    }

    public function panel(): BelongsTo
    {
        return $this->belongsTo(ObservationPanel::class, 'observation_panel_id');
    }

    public function code(): BelongsTo
    {
        return $this->belongsTo(ObservationCode::class, 'observation_code_id');
    }
}
