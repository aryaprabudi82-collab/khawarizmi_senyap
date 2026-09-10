<?php

namespace App\Modules\Platform\Console\Commands;

use App\Modules\Platform\Services\PartitionManager;
use Illuminate\Console\Command;

/**
 * Pemeriksaan kesehatan partisi.
 *
 * Keluar dengan kode 1 kalau ada yang tidak sehat, supaya bisa dipasang
 * sebagai pemeriksaan pemantauan — yang membacanya mesin, bukan orang, dan
 * mesin cuma mengerti kode keluar.
 */
class CheckPartitions extends Command
{
    protected $signature = 'partisi:periksa';

    protected $description = 'Memeriksa runway partisi dan isi partisi DEFAULT';

    public function handle(PartitionManager $partisi): int
    {
        $hasil = $partisi->health();

        $this->table(
            ['Tabel', 'Kolom', 'Runway (bulan)', 'Baris di DEFAULT', 'Status'],
            array_map(fn (array $t) => [
                $t['induk'],
                $t['kolom'],
                $t['runway_bulan'],
                $t['baris_default'],
                $t['aman'] ? 'aman' : 'PERLU TINDAKAN',
            ], $hasil['tabel'])
        );

        foreach ($hasil['tabel'] as $t) {
            if ($t['baris_default'] > 0) {
                /*
                 * Ini kerusakan yang SUDAH terjadi, bukan yang akan
                 * terjadi — dan memperbaikinya jauh lebih mahal daripada
                 * mencegahnya, karena menuntut penguncian tabel.
                 */
                $this->error(sprintf(
                    '%s: %d baris sudah masuk partisi DEFAULT. Partisi rentang untuk '
                    .'bulan-bulan itu TIDAK BISA lagi dibuat sebelum barisnya dipindahkan.',
                    $t['induk'], $t['baris_default']
                ));
            } elseif ($t['runway_bulan'] < PartitionManager::RUNWAY_MINIMAL) {
                $this->warn(sprintf(
                    '%s: runway tinggal %d bulan. Jalankan `php artisan partisi:pastikan`.',
                    $t['induk'], $t['runway_bulan']
                ));
            }
        }

        foreach ($hasil['takTerawat'] as $lain) {
            $this->warn('TIDAK TERAWAT: '.$lain);
        }

        if ($hasil['sehat']) {
            $this->info('Seluruh partisi sehat.');

            return self::SUCCESS;
        }

        return self::FAILURE;
    }
}
