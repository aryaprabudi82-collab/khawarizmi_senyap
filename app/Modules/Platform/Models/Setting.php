<?php

namespace App\Modules\Platform\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Satu pengaturan aplikasi, berkunci dan berriwayat.
 *
 * Tabel `set_*` Khanza rata-rata satu baris tanpa primary key — `set_embalase`
 * benar-benar tanpa kunci, `set_keterlambatan` bahkan MyISAM. Tanpa kunci
 * tidak ada yang mencegah baris kedua muncul, dan tanpa riwayat pertanyaan
 * "sejak kapan nilainya segini" tidak punya jawaban — padahal jawaban itulah
 * yang menentukan apakah tagihan bulan lalu benar.
 */
class Setting extends Model
{
    public const TIPE_TEKS = 'teks';

    public const TIPE_ANGKA = 'angka';

    public const TIPE_UANG = 'uang';

    public const TIPE_BOOLEAN = 'boolean';

    public const TIPE_WAKTU = 'waktu';

    public const TIPE_TANGGAL = 'tanggal';

    public const TIPE_PILIHAN = 'pilihan';

    public const KELOMPOK = ['umum', 'billing', 'farmasi', 'ranap', 'antrian', 'presensi'];

    protected $table = 'platform.settings';

    protected $guarded = ['id'];

    public function revisions(): HasMany
    {
        return $this->hasMany(SettingRevision::class, 'setting_id')
            ->orderByDesc('effective_from')->orderByDesc('id');
    }

    /**
     * Pengaturan yang kuncinya sudah ada tapi nilainya belum ditetapkan.
     *
     * Daftar kejujuran, sejenis kategoriKosong() domain T: pertanyaannya
     * sudah pasti, jawabannya belum — dan itu harus terlihat sebagai
     * pekerjaan yang belum selesai, bukan terbaca sebagai nol.
     */
    public function belumDitetapkan(): bool
    {
        return $this->value === null;
    }

    protected function casts(): array
    {
        return [
            'options' => 'array',
            'is_active' => 'boolean',
        ];
    }
}
