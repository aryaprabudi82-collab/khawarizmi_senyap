<?php

namespace App\Modules\Clinical\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Konsultasi medik antar dokter, berikut jawabannya.
 *
 * PENJAWAB DICATAT SENDIRI. jawaban_konsultasi_medik Khanza tidak punya
 * kolom untuk itu; yang ada di sisi permintaan adalah siapa yang DITANYA,
 * dan dalam praktiknya yang menjawab sering konsulen yang sedang jaga.
 */
class MedicalConsultation extends Model
{
    use SoftDeletes;

    protected $table = 'clinical.medical_consultations';

    protected $guarded = ['id'];

    public const TERBUKA = 'terbuka';

    public const DIJAWAB = 'dijawab';

    public const DIBATALKAN = 'dibatalkan';

    public const JENIS = [
        'konsultasi' => 'Konsultasi',
        'evaluasi' => 'Evaluasi',
        'rawat-bersama' => 'Rawat bersama',
        'alih-rawat' => 'Alih rawat',
        'pre-post-operasi' => 'Pre/post operasi',
    ];

    /**
     * Tingkat kesegeraan — tidak ada di Khanza.
     *
     * Konsultasi cito yang tidak dibedakan akan mengantre di belakang
     * yang rutin, dan yang menunggu adalah pasien yang paling tidak bisa
     * menunggu.
     */
    public const KESEGERAAN = [
        'biasa' => 'Biasa',
        'segera' => 'Segera',
        'cito' => 'Cito',
    ];

    /** Batas wajar menunggu jawaban, per tingkat kesegeraan, dalam jam. */
    public const TENGGAT_JAM = [
        'cito' => 1,
        'segera' => 6,
        'biasa' => 24,
    ];

    protected function casts(): array
    {
        return [
            'requested_at' => 'datetime',
            'answered_at' => 'datetime',
        ];
    }

    public function isAnswered(): bool
    {
        return $this->status === self::DIJAWAB;
    }

    /** Lama menunggu jawaban dalam jam — dihitung, tidak disimpan. */
    public function waitingHours(): ?float
    {
        $sampai = $this->answered_at ?? now();

        return round($this->requested_at->floatDiffInHours($sampai), 2);
    }

    /**
     * Sudah lewat tenggat kesegeraannya.
     *
     * Yang sudah dijawab dinilai terhadap waktu jawabnya; yang belum
     * dinilai terhadap sekarang — itulah gunanya bagi dokter jaga.
     */
    public function isOverdue(): bool
    {
        $tenggat = self::TENGGAT_JAM[$this->urgency] ?? self::TENGGAT_JAM['biasa'];

        return $this->waitingHours() > $tenggat;
    }

    /**
     * Apakah yang menjawab berbeda dari yang ditanya.
     *
     * Bukan kesalahan — konsulen jaga memang sering yang menjawab. Yang
     * salah adalah tidak bisa membedakannya.
     */
    public function answeredBySomeoneElse(): bool
    {
        return $this->isAnswered()
            && $this->consulted_practitioner_name !== null
            && $this->answering_practitioner_name !== $this->consulted_practitioner_name;
    }
}
