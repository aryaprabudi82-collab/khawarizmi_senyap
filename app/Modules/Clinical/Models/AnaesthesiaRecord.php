<?php

namespace App\Modules\Clinical\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Catatan anestesi satu operasi.
 *
 * MELEKAT PADA OPERASI, bukan pada kunjungan: pasien yang dioperasi dua
 * kali dalam satu perawatan punya dua catatan anestesi yang harus bisa
 * dibedakan. Lihat catatan migrasi.
 */
class AnaesthesiaRecord extends Model
{
    use SoftDeletes;

    protected $table = 'clinical.anaesthesia_records';

    protected $guarded = ['id'];

    public const DRAF = 'draf';

    public const FINAL = 'final';

    public const DIBATALKAN = 'dibatalkan';

    public const JENIS = [
        'umum' => 'Anestesi umum',
        'spinal' => 'Anestesi spinal',
        'epidural' => 'Anestesi epidural',
        'blok-perifer' => 'Blok saraf perifer',
        'lokal' => 'Anestesi lokal',
        'sedasi' => 'Sedasi',
    ];

    public const JALAN_NAPAS = [
        'tanpa-alat' => 'Tanpa alat bantu',
        'sungkup' => 'Sungkup muka',
        'lma' => 'Sungkup laring (LMA)',
        'ett' => 'Pipa endotrakeal',
        'trakeostomi' => 'Trakeostomi',
    ];

    protected function casts(): array
    {
        return [
            'anaesthesia_start_at' => 'datetime',
            'surgery_start_at' => 'datetime',
            'surgery_end_at' => 'datetime',
            'anaesthesia_end_at' => 'datetime',
            'finalized_at' => 'datetime',
        ];
    }

    public function recoveryAssessments(): HasMany
    {
        return $this->hasMany(RecoveryAssessment::class, 'anaesthesia_record_id')->orderBy('assessed_at');
    }

    public function isEditable(): bool
    {
        return $this->status === self::DRAF;
    }

    /**
     * Lama anestesi dalam menit — DIHITUNG.
     *
     * Berbeda dari lama bedah, dan memang harus berbeda: anestesi mulai
     * sebelum insisi dan berakhir sesudah luka ditutup.
     */
    public function anaesthesiaMinutes(): ?int
    {
        if ($this->anaesthesia_start_at === null || $this->anaesthesia_end_at === null) {
            return null;
        }

        return (int) round($this->anaesthesia_start_at->diffInMinutes($this->anaesthesia_end_at));
    }

    /** Lama pembedahan dalam menit — DIHITUNG. */
    public function surgeryMinutes(): ?int
    {
        if ($this->surgery_start_at === null || $this->surgery_end_at === null) {
            return null;
        }

        return (int) round($this->surgery_start_at->diffInMinutes($this->surgery_end_at));
    }

    /** Operasi darurat ditandai akhiran E pada kelas ASA. */
    public function isEmergency(): bool
    {
        return str_ends_with((string) $this->asa_class, 'E');
    }
}
