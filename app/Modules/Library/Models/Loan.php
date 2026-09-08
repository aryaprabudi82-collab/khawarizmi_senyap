<?php

namespace App\Modules\Library\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Satu peminjaman.
 *
 * DUA TANGGAL YANG BERBEDA: `due_date` (kapan HARUS kembali) dan
 * `returned_at` (kapan SUNGGUH kembali). Khanza memakai satu kolom
 * `tgl_kembali` untuk keduanya — dan dendanya justru selisih keduanya,
 * jadi dengan satu kolom hanya salah satu pertanyaan yang bisa dijawab.
 */
class Loan extends Model
{
    protected $table = 'library.loans';

    protected $guarded = ['id'];

    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class, 'member_id');
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class, 'item_id');
    }

    public function fines(): HasMany
    {
        return $this->hasMany(Fine::class, 'loan_id');
    }

    public function masihDipinjam(): bool
    {
        return $this->returned_at === null;
    }

    /**
     * Hari keterlambatan. DIHITUNG, tidak disimpan.
     *
     * Untuk pinjaman yang sudah kembali: selisih tanggal kembali dengan
     * jatuh tempo — angkanya berhenti bertambah, sebagaimana seharusnya.
     * Untuk yang belum kembali: selisih hari ini dengan jatuh tempo, dan
     * angkanya memang masih bertambah karena bukunya memang masih di luar.
     *
     * Khanza menambal keterbatasan satu kolomnya dengan menyimpan
     * `keterlambatan` sebagai angka pada tabel denda — nilai beku yang akan
     * salah begitu ada koreksi tanggal, tanpa ada yang tahu kapan.
     */
    public function hariTerlambat(): int
    {
        // Dibandingkan per TANGGAL, bukan per jam: buku yang jatuh tempo
        // tanggal 10 dan dikembalikan tanggal 13 terlambat tiga hari, berapa
        // pun jamnya — denda harian memang dihitung per hari kalender.
        $batas = $this->due_date->copy()->startOfDay();
        $acuan = ($this->returned_at ?? now())->copy()->startOfDay();

        return $acuan->isAfter($batas) ? (int) $batas->diffInDays($acuan) : 0;
    }

    public function terlambat(): bool
    {
        return $this->hariTerlambat() > 0;
    }

    protected function casts(): array
    {
        return [
            'borrowed_at' => 'datetime',
            'due_date' => 'date',
            'returned_at' => 'datetime',
            'policy_daily_fine' => 'decimal:2',
        ];
    }
}
