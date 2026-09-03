<?php

namespace App\Modules\Finance\Database\Seeders;

use App\Modules\Finance\Models\Account;
use Illuminate\Database\Seeder;

/**
 * Bagan akun minimum yang dibutuhkan PostingService/DepositService: satu
 * akun aktif per jenis (kas, piutang, pendapatan, utang). Bukan bagan akun
 * RSP UI yang sebenarnya — itu perlu disusun bersama bagian keuangan
 * sebelum dipakai melayani pasien.
 */
class ChartOfAccountsSeeder extends Seeder
{
    public function run(): void
    {
        $akun = [
            ['1-1000', 'Kas Kasir Rawat Jalan', Account::TYPE_KAS],
            ['1-1200', 'Piutang Penjamin', Account::TYPE_PIUTANG],
            ['2-1000', 'Titipan Deposit Pasien', Account::TYPE_UTANG],
            ['4-1000', 'Pendapatan Rawat Jalan', Account::TYPE_PENDAPATAN],
        ];

        foreach ($akun as [$kode, $nama, $tipe]) {
            Account::query()->updateOrCreate(['code' => $kode], [
                'name' => $nama, 'type' => $tipe, 'is_active' => true,
            ]);
        }

        $this->command?->info('Bagan akun: ' . count($akun) . ' akun dasar disiapkan.');
        $this->command?->warn('Bagan akun lengkap perlu disusun bersama bagian keuangan sebelum dipakai melayani pasien.');
    }
}
