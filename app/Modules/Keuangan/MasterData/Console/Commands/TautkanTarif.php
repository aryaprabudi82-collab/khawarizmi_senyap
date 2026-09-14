<?php

namespace App\Modules\Keuangan\MasterData\Console\Commands;

use App\Modules\Keuangan\MasterData\Application\ChargeItemLinker;
use App\Modules\Keuangan\MasterData\Application\TariffSourceRegistry;
use Illuminate\Console\Command;

/**
 * Menautkan tabel tarif lama ke CDM.
 *
 * MENGAPA PERINTAH, BUKAN SEEDER. Seeder dijalankan sekali saat memasang
 * sistem; penautan ini dijalankan BERULANG KALI — setiap kali ada layanan,
 * obat, atau kamar baru didaftarkan. Menaruhnya di seeder berarti satu-
 * satunya cara menaut item baru adalah menjalankan ulang seluruh seeder,
 * dan di basis data produksi itu bukan pilihan.
 *
 * TIDAK MENGAKTIFKAN APA PUN, dan itu pembatasan yang disengaja. Perintah
 * ini memberi kode; memberi akun dan mengizinkan menagih adalah keputusan
 * akuntansi. Perintah yang ikut mengaktifkan akan membuat item bisa
 * ditagihkan tanpa seorang pun memutuskan akun pendapatannya.
 */
class TautkanTarif extends Command
{
    protected $signature = 'keuangan:tautkan-tarif
        {--konteks= : Batasi ke satu konteks sumber (catalog/pharmacy/inpatient/retail/parking)}
        {--berlaku-dari= : Tanggal mulai berlaku item baru (Y-m-d), bawaan hari ini}
        {--lihat-saja : Tampilkan apa yang AKAN ditaut tanpa menulis apa pun}';

    protected $description = 'Menautkan tarif lama (layanan, obat, kamar, koperasi, parkir) ke Charge Description Master';

    public function handle(TariffSourceRegistry $registry, ChargeItemLinker $penaut): int
    {
        $konteks = $this->option('konteks') ?: null;

        if ($konteks !== null && ! in_array($konteks, $registry->konteksTerdaftar(), true)) {
            $this->error("Konteks '{$konteks}' tidak dikenal. Yang tersedia: "
                .implode(', ', $registry->konteksTerdaftar()));

            return self::FAILURE;
        }

        $belum = $registry->belumTertaut();

        if ($konteks !== null) {
            $belum = $belum->only([$konteks]);
        }

        if ($belum->isEmpty()) {
            $this->info('Tidak ada sumber tarif yang belum tertaut.');

            return self::SUCCESS;
        }

        $this->line('');
        $this->line('Yang belum tertaut:');

        foreach ($belum as $namaKonteks => $baris) {
            $this->line("  <fg=cyan>{$namaKonteks}</> — {$baris->count()} baris");

            foreach ($baris->take(5) as $b) {
                $this->line("      {$b['code']}  {$b['name']}  <fg=gray>[{$b['golongan']}]</>");
            }

            if ($baris->count() > 5) {
                $this->line('      <fg=gray>… dan '.($baris->count() - 5).' lainnya</>');
            }
        }

        $this->line('');

        if ($this->option('lihat-saja')) {
            $this->comment('Mode lihat-saja — tidak ada yang ditulis.');

            return self::SUCCESS;
        }

        $hasil = $penaut->sapu($konteks, $this->option('berlaku-dari') ?: null);

        $this->info("Ditaut: {$hasil['ditaut']} item.");

        if ($hasil['dilewati'] > 0) {
            $this->warn("Dilewati: {$hasil['dilewati']} — lihat rinciannya:");

            foreach ($hasil['galat'] as $pesan) {
                $this->line('  <fg=yellow>!</> '.$pesan);
            }
        }

        /*
         * Yang dikatakan SETELAH penautan sama pentingnya dengan
         * penautannya. Tanpa kalimat ini, orang yang menjalankan perintah
         * ini akan mengira pekerjaannya selesai — padahal tidak satu pun
         * item hasil sapuan bisa ditagihkan sampai akunnya dipetakan.
         */
        $ringkasan = $penaut->ringkasan();

        $this->line('');
        $this->line("Item CDM berjalan : {$ringkasan['total']}");
        $this->line("Belum dipetakan   : <fg=yellow>{$ringkasan['belum_dipetakan']}</>");
        $this->line("Aktif (bisa ditagih): {$ringkasan['aktif']}");

        if ($ringkasan['belum_dipetakan'] > 0) {
            $this->line('');
            $this->warn(
                'Item yang belum dipetakan ke akun TIDAK BISA diaktifkan dan tidak bisa ditagihkan. '
                .'Pemetaan akun adalah keputusan akuntansi — akun mana yang menampung pendapatan '
                .'tiap golongan layanan — dan perlu disusun bersama bagian keuangan.'
            );
        }

        return self::SUCCESS;
    }
}
