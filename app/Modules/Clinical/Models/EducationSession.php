<?php

namespace App\Modules\Clinical\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Pelaksanaan edukasi pada pasien atau keluarganya.
 *
 * PENGULANGAN MENUNJUK APA YANG DIULANG. Khanza hanya punya status
 * enum('Awal','Ulang') tanpa menyebut pengulangan atas apa, sehingga
 * tidak bisa dijawab apakah materi yang gagal diverifikasi benar-benar
 * diulang.
 */
class EducationSession extends Model
{
    use SoftDeletes;

    protected $table = 'clinical.education_sessions';

    protected $guarded = ['id'];

    public const SUDAH_MENGERTI = 'sudah-mengerti';

    public const PERLU_RE_EDUKASI = 'perlu-re-edukasi';

    public const PERLU_RE_DEMONSTRASI = 'perlu-re-demonstrasi';

    public const VERIFIKASI = [
        self::SUDAH_MENGERTI => 'Sudah mengerti',
        self::PERLU_RE_EDUKASI => 'Perlu re-edukasi',
        self::PERLU_RE_DEMONSTRASI => 'Perlu re-demonstrasi',
    ];

    /** Hasil verifikasi yang menuntut pengulangan. */
    public const PERLU_DIULANG = [self::PERLU_RE_EDUKASI, self::PERLU_RE_DEMONSTRASI];

    public const PENERIMA = [
        'pasien' => 'Pasien',
        'keluarga' => 'Keluarga',
        'pasien-dan-keluarga' => 'Pasien dan keluarga',
        'lainnya' => 'Lainnya',
    ];

    public const METODE = [
        'ceramah' => 'Ceramah',
        'diskusi' => 'Diskusi',
        'demonstrasi' => 'Demonstrasi',
        'media-cetak' => 'Media cetak',
        'audio-visual' => 'Audio-visual',
    ];

    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'ended_at' => 'datetime',
        ];
    }

    public function repeatsSession(): BelongsTo
    {
        return $this->belongsTo(self::class, 'repeats_session_id');
    }

    public function repeatedBy(): HasOne
    {
        return $this->hasOne(self::class, 'repeats_session_id');
    }

    /** Lama edukasi dalam menit — dihitung, bukan diketik. */
    public function durationMinutes(): ?int
    {
        if ($this->ended_at === null) {
            return null;
        }

        return (int) round($this->started_at->diffInMinutes($this->ended_at));
    }

    public function needsRepeat(): bool
    {
        return in_array($this->verification, self::PERLU_DIULANG, true);
    }

    /**
     * Perlu diulang dan belum ada pengulangannya.
     *
     * Inilah yang tidak bisa dijawab Khanza: penanda "Ulang" tidak
     * menyebut apa yang diulang, jadi tidak ada cara mengetahui materi
     * mana yang masih menggantung.
     */
    public function isRepeatOutstanding(): bool
    {
        return $this->needsRepeat() && ! $this->repeatedBy()->exists();
    }
}
