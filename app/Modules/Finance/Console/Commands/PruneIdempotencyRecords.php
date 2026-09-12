<?php

namespace App\Modules\Finance\Console\Commands;

use App\Modules\Finance\Services\IdempotencyGuard;
use App\Modules\Platform\Services\ScheduledTaskLog;
use Illuminate\Console\Command;

/**
 * Membersihkan catatan idempotensi yang sudah lewat masa simpannya.
 *
 * Catatan penahan adalah pelindung terhadap pengiriman ulang yang terjadi
 * dalam hitungan detik sampai jam — BUKAN jejak audit. Jejaknya ada di
 * platform.audit_logs. Menyimpannya selamanya membuat tabel ini tumbuh
 * sebesar tabel transaksinya sendiri tanpa menambah satu pun perlindungan.
 *
 * DICATAT WALAU TIDAK ADA YANG DIBERSIHKAN. Yang hendak dibuktikan catatan
 * ini adalah perawatannya masih berjalan, dan hari-hari saat tidak ada
 * yang kedaluwarsa justru mayoritasnya. Mencatat hanya saat ada yang
 * terhapus membuat perawatan yang sehat tampak mati berbulan-bulan.
 */
class PruneIdempotencyRecords extends Command
{
    protected $signature = 'keuangan:bersihkan-idempotensi';

    protected $description = 'Membersihkan catatan idempotensi keuangan yang sudah kedaluwarsa';

    public function handle(IdempotencyGuard $guard, ScheduledTaskLog $jejak): int
    {
        $terhapus = $guard->bersihkanKedaluwarsa();

        $ringkas = $terhapus === 0
            ? 'Tidak ada catatan kedaluwarsa.'
            : $terhapus.' catatan idempotensi dibersihkan.';

        $jejak->record(self::JEJAK, $ringkas);

        $this->info($ringkas);

        return self::SUCCESS;
    }

    public const JEJAK = 'keuangan-idempotensi';
}
