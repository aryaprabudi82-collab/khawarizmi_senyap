<?php

namespace App\Modules\Clinical\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Satu obat dalam rekonsiliasi.
 *
 * TINDAK LANJUTNYA TIGA, BUKAN DUA. Khanza hanya mengenal Lanjut dan
 * Stop lalu menaruh perubahan aturan pakai di kolom teks terpisah,
 * sehingga obat yang diteruskan dengan dosis berbeda tercatat sebagai
 * "Lanjut" begitu saja.
 */
class MedicationReconciliationItem extends Model
{
    protected $table = 'clinical.medication_reconciliation_items';

    protected $guarded = ['id'];

    public const LANJUT = 'lanjut';

    public const STOP = 'stop';

    public const UBAH_ATURAN = 'ubah-aturan';

    public const TINDAK_LANJUT = [
        self::LANJUT => 'Diteruskan apa adanya',
        self::STOP => 'Dihentikan',
        self::UBAH_ATURAN => 'Diteruskan dengan aturan pakai berbeda',
    ];

    protected function casts(): array
    {
        return [
            'last_taken_at' => 'datetime',
        ];
    }

    public function reconciliation(): BelongsTo
    {
        return $this->belongsTo(MedicationReconciliation::class, 'reconciliation_id');
    }
}
