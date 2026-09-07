<?php

namespace App\Modules\Clinical\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Rekonsiliasi obat — wawancara tentang obat yang sedang dipakai pasien,
 * termasuk yang dibawa dari luar rumah sakit.
 *
 * Alergi TIDAK disimpan di sini sebagai daftar yang hidup; yang hidup
 * tetap clinical.allergies. Lihat catatan migrasi.
 */
class MedicationReconciliation extends Model
{
    use SoftDeletes;

    protected $table = 'clinical.medication_reconciliations';

    protected $guarded = ['id'];

    public const DRAF = 'draf';

    public const FINAL = 'final';

    public const DIBATALKAN = 'dibatalkan';

    /**
     * Empat kesempatan rekonsiliasi, diambil apa adanya dari
     * rekonsiliasi_obat_saat Khanza — keempatnya memang titik ketika
     * daftar obat pasien berubah tangan.
     */
    public const KESEMPATAN = [
        'admisi' => 'Saat admisi',
        'transfer-antar-ruang' => 'Saat transfer antar ruang',
        'pindah-faskes-lain' => 'Saat pindah ke faskes lain',
        'pulang' => 'Saat pulang',
    ];

    protected function casts(): array
    {
        return [
            'allergies_at_interview' => 'array',
            'interviewed_at' => 'datetime',
            'received_by_pharmacy_at' => 'datetime',
            'confirmed_by_pharmacist_at' => 'datetime',
            'handed_to_patient_at' => 'datetime',
            'finalized_at' => 'datetime',
        ];
    }

    public function items(): HasMany
    {
        return $this->hasMany(MedicationReconciliationItem::class, 'reconciliation_id');
    }

    public function isEditable(): bool
    {
        return $this->status === self::DRAF;
    }
}
