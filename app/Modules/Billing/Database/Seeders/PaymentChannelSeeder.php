<?php

namespace App\Modules\Billing\Database\Seeders;

use App\Modules\Billing\Models\PaymentChannel;
use Illuminate\Database\Seeder;

/**
 * Kanal pembayaran awal (domain K item G).
 *
 * Sengaja hanya kanal yang MASUK AKAL untuk RSP UI di Depok, bukan kelima
 * bank yang kebetulan ada di Khanza. Bank Jateng dan Bank Papua adalah
 * bank pembangunan daerah provinsi lain; memasangnya di sini cuma karena
 * Khanza punya kodenya akan menghasilkan daftar kanal yang tidak pernah
 * dipakai, dan daftar yang penuh pilihan mati membuat petugas ragu
 * memilih.
 *
 * Nomor rekeningnya DIBIARKAN KOSONG. Menebak nomor rekening rumah sakit
 * jauh lebih berbahaya daripada mengosongkannya — uang bisa masuk ke
 * rekening yang salah, dan kesalahan itu baru ketahuan saat rekonsiliasi.
 * Kanal tetap bisa dipakai tanpa nomor rekening; yang tidak boleh cuma
 * menebaknya.
 */
class PaymentChannelSeeder extends Seeder
{
    public function run(): void
    {
        $kanal = [
            ['VA-MANDIRI', 'Virtual Account Bank Mandiri', 'virtual-account', 'Bank Mandiri'],
            ['VA-BRI', 'BRIVA (Virtual Account BRI)', 'virtual-account', 'Bank BRI'],
            ['VA-BJB', 'Virtual Account Bank BJB', 'virtual-account', 'Bank BJB'],
            ['TRF-MANDIRI', 'Transfer Bank Mandiri', 'transfer', 'Bank Mandiri'],
            ['QRIS', 'QRIS', 'qris', null],
            ['EDC', 'Kartu Debit/Kredit (EDC)', 'edc', null],
        ];

        foreach ($kanal as [$code, $name, $kind, $bank]) {
            PaymentChannel::query()->updateOrCreate(
                ['code' => $code],
                [
                    'name' => $name,
                    'kind' => $kind,
                    'bank_name' => $bank,
                    'is_active' => true,
                    'allows_ralan' => true,
                    'allows_ranap' => true,
                ],
            );
        }

        $this->command?->info('Kanal pembayaran: ' . count($kanal) . ' kanal disiapkan (nomor rekening sengaja dikosongkan).');
    }
}
