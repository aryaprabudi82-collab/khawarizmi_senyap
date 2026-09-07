<?php

namespace App\Modules\Clinical\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * Satu episode pemasangan restrain.
 *
 * BENTUKNYA EPISODE, BUKAN BARIS PENILAIAN. pengkajian_restrain Khanza
 * tidak punya perintah dokter, jam mulai, jam lepas, maupun tautan ke
 * penilaian ulangnya — sehingga pertanyaan pokok setiap peninjauan
 * restrain, berapa lama pasien terikat, tidak bisa dijawab.
 */
class RestraintEpisode extends Model
{
    use SoftDeletes;

    protected $table = 'clinical.restraint_episodes';

    protected $guarded = ['id'];

    public const BERJALAN = 'berjalan';

    public const DILEPAS = 'dilepas';

    public const DIBATALKAN = 'dibatalkan';

    /**
     * Masa berlaku perintah restrain, dalam jam.
     *
     * Praktik yang lazim untuk pasien dewasa. Dipakai MENAGIH pembaruan
     * perintah, bukan melepas pasien: melepas orang yang masih
     * berbahaya karena perintahnya kedaluwarsa jelas lebih berbahaya
     * daripada perintah yang telat diperbarui.
     */
    public const MASA_PERINTAH_JAM = 24;

    /** Tenggat penilaian ulang, dalam menit. */
    public const TENGGAT_TINJAU_MENIT = 120;

    public const INDIKASI = [
        'membahayakan-diri' => 'Membahayakan diri sendiri',
        'membahayakan-orang-lain' => 'Membahayakan orang lain',
        'mengganggu-terapi-penting' => 'Mengganggu terapi yang menopang nyawa',
    ];

    /**
     * Upaya yang lebih ringan, yang harus dicoba lebih dulu.
     *
     * Bukan hiasan: restrain adalah pilihan terakhir, dan yang harus
     * dibuktikan justru bahwa cara lain sudah dicoba dan gagal.
     */
    public const ALTERNATIF = [
        'pendekatan-verbal' => 'Pendekatan verbal dan menenangkan',
        'pendampingan-keluarga' => 'Pendampingan keluarga',
        'pengalihan-perhatian' => 'Pengalihan perhatian',
        'penyesuaian-lingkungan' => 'Penyesuaian lingkungan (cahaya, kebisingan)',
        'pemenuhan-kebutuhan-dasar' => 'Pemenuhan kebutuhan dasar (nyeri, haus, eliminasi)',
        'penjagaan-satu-satu' => 'Penjagaan satu-satu oleh petugas',
        'peninjauan-obat' => 'Peninjauan obat yang mungkin memicu delirium',
    ];

    public const JENIS = [
        'pergelangan-tangan-kanan' => 'Pergelangan tangan kanan',
        'pergelangan-tangan-kiri' => 'Pergelangan tangan kiri',
        'pergelangan-kaki-kanan' => 'Pergelangan kaki kanan',
        'pergelangan-kaki-kiri' => 'Pergelangan kaki kiri',
        'badan' => 'Badan',
        'sarung-tangan' => 'Sarung tangan pelindung',
        'pengaman-tempat-tidur' => 'Pengaman tempat tidur',
    ];

    protected function casts(): array
    {
        return [
            'ordered_at' => 'datetime',
            'order_expires_at' => 'datetime',
            'started_at' => 'datetime',
            'released_at' => 'datetime',
            'alternatives_tried' => 'array',
            'restraint_types' => 'array',
            'family_informed' => 'boolean',
        ];
    }

    public function reviews(): HasMany
    {
        return $this->hasMany(RestraintReview::class, 'episode_id')->orderBy('reviewed_at');
    }

    public function isRunning(): bool
    {
        return $this->status === self::BERJALAN;
    }

    /** Lama pengekangan dalam menit — dihitung, tidak disimpan. */
    public function durationMinutes(): int
    {
        $sampai = $this->released_at ?? now();

        return (int) round($this->started_at->diffInMinutes($sampai));
    }

    /**
     * Jumlah titik pengekangan.
     *
     * Inilah yang hilang saat jenisnya cuma satu pilihan: pasien yang
     * diikat empat titik dan pasien yang diikat satu titik terbaca sama.
     */
    public function restraintPointCount(): int
    {
        return count($this->restraint_types ?? []);
    }

    /** Perintahnya sudah lewat masa berlaku dan pasien masih terikat. */
    public function hasExpiredOrder(): bool
    {
        return $this->isRunning() && $this->order_expires_at->isPast();
    }

    /** Sudah lewat tenggat penilaian ulang. */
    public function isReviewOverdue(): bool
    {
        if (! $this->isRunning()) {
            return false;
        }

        $terakhir = $this->reviews()->max('reviewed_at');
        $sejak = $terakhir !== null ? Carbon::parse($terakhir) : $this->started_at;

        return $sejak->addMinutes(self::TENGGAT_TINJAU_MENIT)->isPast();
    }

    /**
     * Restrain dipasang tanpa satu pun upaya yang lebih ringan dicoba
     * lebih dulu.
     *
     * Bukan pelanggaran yang dihalangi sistem — kegawatan nyata memang
     * ada — melainkan temuan yang harus terlihat saat ditinjau.
     */
    public function hadNoAlternativesTried(): bool
    {
        return ($this->alternatives_tried ?? []) === [];
    }
}
