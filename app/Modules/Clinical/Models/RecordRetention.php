<?php

namespace App\Modules\Clinical\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Masa simpan rekam medis seorang pasien.
 *
 * TANGGAL RETENSI DIHITUNG, TIDAK DISIMPAN. retensi_pasien Khanza
 * menyimpan tgl_retensi di samping terakhir_daftar; begitu masa
 * simpannya diubah peraturan, seluruh barisnya basi tanpa ada yang
 * tahu.
 */
class RecordRetention extends Model
{
    protected $table = 'clinical.record_retentions';

    protected $guarded = ['id'];

    public const AKTIF = 'aktif';

    public const DIUSULKAN_MUSNAH = 'diusulkan-musnah';

    public const DIMUSNAHKAN = 'dimusnahkan';

    public const DIABADIKAN = 'diabadikan';

    /**
     * Masa simpan rekam medis elektronik.
     *
     * Permenkes 24/2022 Pasal 39: sekurang-kurangnya 25 tahun sejak
     * tanggal terakhir pasien berobat. Satu konstanta, dipakai seluruh
     * hitungan — kalau peraturannya berubah, yang diubah cuma angka ini
     * dan seluruh tanggal ikut benar dengan sendirinya.
     */
    public const MASA_SIMPAN_TAHUN = 25;

    protected function casts(): array
    {
        return [
            'last_visit_on' => 'date',
            'proposed_on' => 'date',
            'destroyed_at' => 'datetime',
        ];
    }

    /** Padanan tgl_retensi Khanza — DIHITUNG. */
    public function dueOn(): Carbon
    {
        return $this->last_visit_on->copy()->addYears(self::MASA_SIMPAN_TAHUN);
    }

    /** Sudah lewat masa simpannya. */
    public function isDue(?Carbon $on = null): bool
    {
        return $this->dueOn()->lessThanOrEqualTo($on ?? now());
    }

    /** Sisa tahun sebelum boleh dimusnahkan; negatif berarti sudah lewat. */
    public function yearsRemaining(): int
    {
        return (int) floor(now()->floatDiffInYears($this->dueOn(), false));
    }

    public function isDestroyed(): bool
    {
        return $this->status === self::DIMUSNAHKAN;
    }
}
