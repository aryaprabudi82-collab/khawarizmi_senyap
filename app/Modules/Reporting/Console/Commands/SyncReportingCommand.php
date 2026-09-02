<?php

namespace App\Modules\Reporting\Console\Commands;

use App\Modules\Reporting\Services\ReportingSyncService;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

/**
 * php artisan reporting:sync {tanggal?}
 *
 * Tanpa argumen, menyinkronkan hari ini — cocok dijadwalkan tiap jam lewat
 * Laravel Scheduler supaya dashboard selalu dekat waktu nyata. Menerima
 * tanggal eksplisit untuk menyinkronkan ulang hari yang sudah lewat, mis.
 * setelah koreksi data.
 */
class SyncReportingCommand extends Command
{
    protected $signature = 'reporting:sync {tanggal? : Format YYYY-MM-DD, bawaan hari ini}';

    protected $description = 'Hitung ulang read model reporting (kunjungan, diagnosis, pendapatan) untuk satu tanggal';

    public function handle(ReportingSyncService $sync): int
    {
        $date = $this->argument('tanggal') !== null
            ? CarbonImmutable::parse($this->argument('tanggal'))
            : CarbonImmutable::now();

        $hasil = $sync->syncDay($date);

        $this->info(sprintf(
            'Reporting %s disinkronkan: %d kelompok kunjungan, %d kode diagnosis, %d kelompok pendapatan.',
            $date->toDateString(),
            $hasil['kunjungan'],
            $hasil['diagnosis'],
            $hasil['pendapatan'],
        ));

        return self::SUCCESS;
    }
}
