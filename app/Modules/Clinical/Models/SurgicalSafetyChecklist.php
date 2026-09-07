<?php

namespace App\Modules\Clinical\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Satu fase daftar tilik keselamatan bedah WHO.
 *
 * Melekat pada OPERASINYA, bukan pada kunjungan: seluruh gunanya adalah
 * memastikan tepat prosedur, dan daftar tilik yang tidak tahu ia
 * melindungi prosedur yang mana sudah kehilangan maksudnya.
 */
class SurgicalSafetyChecklist extends Model
{
    protected $table = 'clinical.surgical_safety_checklists';

    protected $guarded = ['id'];

    public const SIGN_IN = 'sign-in';
    public const TIME_OUT = 'time-out';
    public const SIGN_OUT = 'sign-out';

    /** Urutan fase — bukan tata letak layar, melainkan isi aturannya. */
    public const URUTAN = [self::SIGN_IN, self::TIME_OUT, self::SIGN_OUT];

    public const LABEL = [
        self::SIGN_IN => 'Sign In (sebelum induksi anestesi)',
        self::TIME_OUT => 'Time Out (sebelum insisi kulit)',
        self::SIGN_OUT => 'Sign Out (sebelum menutup luka)',
    ];

    protected function casts(): array
    {
        return ['answers' => 'array', 'performed_at' => 'datetime'];
    }

    public function operation(): BelongsTo
    {
        return $this->belongsTo(Operation::class, 'operation_id');
    }

    /** Fase yang harus sudah terisi sebelum fase ini boleh diisi. */
    public function previousPhase(): ?string
    {
        $i = array_search($this->phase, self::URUTAN, true);

        return $i > 0 ? self::URUTAN[$i - 1] : null;
    }
}
