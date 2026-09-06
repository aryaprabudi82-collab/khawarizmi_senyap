<?php

namespace App\Modules\Finance\Database\Seeders;

use App\Modules\Finance\Models\ReceivableCategory;
use Illuminate\Database\Seeder;

/**
 * Kategori piutang non-pasien (domain K item C).
 *
 * account_id sengaja dibiarkan kosong, alasan yang sama seperti kategori
 * kas di item A: bagan akun RSP UI belum lengkap, dan menebak pemetaannya
 * menaruh uang di akun yang salah — kesalahan yang jauh lebih sulit
 * ditemukan daripada kategori yang belum dipetakan.
 */
class ReceivableCategorySeeder extends Seeder
{
    public function run(): void
    {
        $kategori = [
            ['PJP-01', 'MCU Karyawan Perusahaan', 'jasa-perusahaan'],
            ['PJP-02', 'Pelayanan Kesehatan Karyawan', 'jasa-perusahaan'],
            ['PJP-03', 'Kerja Sama Layanan Lain', 'jasa-perusahaan'],

            ['PPU-01', 'Pinjaman Pegawai', 'peminjaman-uang'],
            ['PPU-02', 'Uang Muka Kegiatan', 'peminjaman-uang'],
        ];

        foreach ($kategori as [$code, $name, $kind]) {
            ReceivableCategory::query()->updateOrCreate(
                ['code' => $code],
                ['name' => $name, 'kind' => $kind, 'is_active' => true],
            );
        }

        $this->command?->info('Kategori piutang non-pasien: ' . count($kategori) . ' pos disiapkan (belum dipetakan ke bagan akun).');
    }
}
