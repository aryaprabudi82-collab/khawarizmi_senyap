<?php

namespace App\Modules\Correspondence\Models;

use Illuminate\Database\Eloquent\Model;

class PatientRequest extends Model
{
    public const STATUS_DIMINTA = 'diminta';

    public const STATUS_DIPENUHI = 'dipenuhi';

    public const STATUS_DITOLAK = 'ditolak';

    public const JENIS_BINROHTAL = 'binrohtal';

    public const JENIS_PERLINDUNGAN = 'perlindungan-kekerasan';

    public const JENIS_PRIVASI = 'privasi';

    public const JENIS_SECOND_OPINION = 'second-opinion';

    public const JENIS_CUTI = 'cuti-perawatan';

    public const JENIS = [
        self::JENIS_BINROHTAL, self::JENIS_PERLINDUNGAN, self::JENIS_PRIVASI,
        self::JENIS_SECOND_OPINION, self::JENIS_CUTI,
    ];

    /** Kosakata yang sama dengan penanda tangan persetujuan — satu daftar, bukan dua. */
    public const HUBUNGAN = [
        'diri-sendiri', 'suami', 'istri', 'ayah', 'ibu',
        'anak', 'saudara-kandung', 'pengampu', 'lainnya',
    ];

    public const LABEL = [
        self::JENIS_BINROHTAL => 'Bimbingan Rohani & Mental',
        self::JENIS_PERLINDUNGAN => 'Perlindungan dari Kekerasan',
        self::JENIS_PRIVASI => 'Permohonan Privasi',
        self::JENIS_SECOND_OPINION => 'Permintaan Second Opinion',
        self::JENIS_CUTI => 'Pengajuan Cuti Perawatan',
    ];

    protected $table = 'correspondence.patient_requests';

    protected $guarded = ['id'];

    public function belumDijawab(): bool
    {
        return $this->status === self::STATUS_DIMINTA;
    }

    /**
     * Lama menunggu jawaban, dalam jam. DIHITUNG, tidak disimpan — angka
     * yang disimpan akan berhenti bertambah begitu barisnya tidak disentuh
     * lagi, padahal justru permintaan yang tidak disentuh yang perlu
     * dilihat.
     */
    public function jamMenunggu(): ?float
    {
        if (! $this->belumDijawab()) {
            return null;
        }

        return round($this->requested_at->diffInMinutes(now()) / 60, 2);
    }

    public function label(): string
    {
        return self::LABEL[$this->request_type] ?? $this->request_type;
    }

    protected function casts(): array
    {
        return [
            'requested_at' => 'datetime',
            'responded_at' => 'datetime',
            'leave_starts_at' => 'date',
            'leave_ends_at' => 'date',
        ];
    }
}
