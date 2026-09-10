<?php

namespace App\Modules\Platform\Console\Commands;

use App\Modules\Platform\Services\PartitionManager;
use Illuminate\Console\Command;

/**
 * Menambah partisi bulanan sebelum dibutuhkan.
 *
 * Dijadwalkan bulanan (lihat routes/console.php). Idempoten — menjalankannya
 * berkali-kali sehari tidak melakukan apa pun kecuali membaca katalog.
 */
class EnsurePartitions extends Command
{
    protected $signature = 'partisi:pastikan
                            {--bulan= : Berapa bulan ke depan yang harus tersedia}
                            {--kering : Tampilkan yang akan dibuat, jangan buat}';

    protected $description = 'Memastikan tabel berpartisi punya partisi bulan-bulan berikutnya';

    public function handle(PartitionManager $partisi): int
    {
        $bulan = (int) ($this->option('bulan') ?: PartitionManager::RUNWAY_BULAN);

        $this->info('Memeriksa runway partisi (target '.$bulan.' bulan ke depan)...');

        foreach ($partisi->partitionedTables() as $t) {
            $this->line(sprintf(
                '  %-32s runway %d bulan, terakhir tercakup %s',
                $t['induk'],
                $partisi->runwayMonths($t['induk']),
                $partisi->lastCoveredMonth($t['induk'])?->format('F Y') ?? '— tidak ada —'
            ));
        }

        if ($this->option('kering')) {
            $this->warn('Mode kering: tidak ada partisi yang dibuat.');

            return self::SUCCESS;
        }

        $dibuat = $partisi->ensureRunway($bulan);

        if ($dibuat === []) {
            $this->info('Runway sudah cukup. Tidak ada partisi baru yang perlu dibuat.');
        } else {
            $this->info(count($dibuat).' partisi dibuat:');

            foreach ($dibuat as $nama) {
                $this->line('  + '.$nama);
            }
        }

        /*
         * Tabel berpartisi yang bentuknya di luar RANGE bulanan TIDAK
         * terawat perintah ini, dan itu dilaporkan — bukan didiamkan di
         * balik pesan "selesai" yang menyiratkan semuanya beres.
         */
        foreach ($partisi->unmanaged() as $lain) {
            $this->warn('  TIDAK TERAWAT: '.$lain.' — bentuk partisinya di luar RANGE bulanan.');
        }

        return self::SUCCESS;
    }
}
