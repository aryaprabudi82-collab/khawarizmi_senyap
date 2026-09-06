<?php

namespace App\Modules\Finance\Database\Seeders;

use App\Modules\Finance\Models\CashCategory;
use Illuminate\Database\Seeder;

/**
 * Kategori kas awal (domain K item A).
 *
 * Daftar ini sengaja pendek dan umum — pos kas tiap rumah sakit berbeda,
 * dan menebak daftar panjang yang spesifik hanya akan membuat petugas
 * memilih kategori yang paling mirip alih-alih yang benar.
 *
 * account_id sengaja DIBIARKAN KOSONG: bagan akun RSP UI belum lengkap
 * (baru 4 akun contoh sejak domain I item E), dan menebak pemetaannya
 * akan menaruh uang di akun yang salah — kesalahan yang jauh lebih sulit
 * ditemukan daripada kategori yang belum dipetakan, karena angkanya tetap
 * muncul dan tetap terlihat masuk akal. Layar kas menampilkan total yang
 * belum dipetakan supaya kekosongan ini terlihat, bukan terlupakan.
 */
class CashCategorySeeder extends Seeder
{
    public function run(): void
    {
        $kategori = [
            ['KM-01', 'Sewa Lahan & Ruang', 'masuk'],
            ['KM-02', 'Jasa Parkir', 'masuk'],
            ['KM-03', 'Penjualan Barang Bekas', 'masuk'],
            ['KM-04', 'Pemasukan Lain-lain', 'masuk'],

            ['KK-01', 'Listrik, Air & Telepon', 'keluar'],
            ['KK-02', 'Bahan Bakar & Transportasi', 'keluar'],
            ['KK-03', 'Pemeliharaan Gedung & Alat', 'keluar'],
            ['KK-04', 'Alat Tulis & Cetak', 'keluar'],
            ['KK-05', 'Konsumsi & Rapat', 'keluar'],
            ['KK-06', 'Pengeluaran Lain-lain', 'keluar'],
        ];

        foreach ($kategori as [$code, $name, $direction]) {
            CashCategory::query()->updateOrCreate(
                ['code' => $code],
                ['name' => $name, 'direction' => $direction, 'is_active' => true],
            );
        }

        $this->command?->info('Kategori kas: ' . count($kategori) . ' pos disiapkan (belum dipetakan ke bagan akun).');
    }
}
