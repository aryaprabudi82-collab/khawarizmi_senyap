<?php

namespace App\Modules\Clinical\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Penilaian ulang selama restrain berjalan.
 *
 * Tabel ini tidak ada padanannya di Khanza: baris pengkajian_restrain
 * boleh berulang, tapi tidak ada yang menautkannya ke episode yang sama,
 * jadi tidak bisa dijawab apakah seorang pasien pernah ditinjau ulang
 * selama terikat.
 */
class RestraintReview extends Model
{
    protected $table = 'clinical.restraint_reviews';

    protected $guarded = ['id'];

    public const SIRKULASI = [
        'baik' => 'Baik',
        'menurun' => 'Menurun',
        'tidak-teraba' => 'Tidak teraba',
    ];

    public const KULIT = [
        'utuh' => 'Utuh',
        'kemerahan' => 'Kemerahan',
        'lecet' => 'Lecet',
        'luka' => 'Luka',
    ];

    /** Temuan yang menuntut restrain dilepas atau dilonggarkan seketika. */
    public const TEMUAN_GAWAT_SIRKULASI = ['menurun', 'tidak-teraba'];

    public const TEMUAN_GAWAT_KULIT = ['lecet', 'luka'];

    protected function casts(): array
    {
        return [
            'reviewed_at' => 'datetime',
            'position_changed' => 'boolean',
            'basic_needs_met' => 'boolean',
            'still_needed' => 'boolean',
        ];
    }

    public function episode(): BelongsTo
    {
        return $this->belongsTo(RestraintEpisode::class, 'episode_id');
    }

    /**
     * Ada cedera akibat pengekangannya.
     *
     * Disebutkan, bukan dipakai melarang: yang melonggarkan ikatan
     * adalah perawat di samping pasien, dan sistem yang menolak
     * mencatat tidak melonggarkan apa pun.
     */
    public function hasRestraintInjury(): bool
    {
        return in_array($this->circulation, self::TEMUAN_GAWAT_SIRKULASI, true)
            || in_array($this->skin_condition, self::TEMUAN_GAWAT_KULIT, true);
    }
}
