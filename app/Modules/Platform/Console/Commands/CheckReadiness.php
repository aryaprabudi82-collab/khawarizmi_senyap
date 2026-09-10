<?php

namespace App\Modules\Platform\Console\Commands;

use App\Modules\Platform\Services\OperationalReadiness;
use Illuminate\Console\Command;

/**
 * Pemeriksaan kesiapan operasional.
 *
 * MENGAPA INI ADA. Seluruh 2.000-an uji di repositori ini menjawab
 * pertanyaan "apakah kodenya benar". Tidak satu pun menjawab pertanyaan yang
 * ditanyakan pada hari pemasangan: "apakah sistem ini sudah bisa dipakai
 * besok pagi".
 *
 * Keduanya berbeda, dan bedanya besar. Kode yang seluruhnya benar tetap
 * tidak bisa dipakai kalau shift kasir belum didefinisikan (kasir tidak bisa
 * menutup shift sama sekali), kalau identitas rumah sakit belum diisi (nama
 * rumah sakit tidak muncul di satu pun kuitansi), atau kalau tarif embalase
 * belum ditetapkan (obat tertagih tanpa embalase, diam-diam).
 *
 * Seluruh kekosongan itu DISENGAJA — daftar yang isinya diskresi RSP UI
 * sengaja lahir kosong daripada diisi tebakan. Tapi keputusan itu hanya
 * bertanggung jawab kalau kekosongannya BISA DILIHAT. Perintah ini yang
 * membuatnya terlihat, sebagai daftar pekerjaan, bukan sebagai kejutan pada
 * hari pertama.
 *
 * Keluar dengan kode 1 bila ada yang MENGHALANGI operasi, supaya bisa
 * dipasang sebagai gerbang sebelum penggelaran.
 */
class CheckReadiness extends Command
{
    protected $signature = 'siap:periksa {--semua : Tampilkan juga yang sudah beres}';

    protected $description = 'Memeriksa apakah sistem sudah bisa dipakai operasional';

    public function handle(OperationalReadiness $siap): int
    {
        $hasil = $siap->check();

        $penghalang = array_filter($hasil, fn (array $p) => $p['status'] === OperationalReadiness::MENGHALANGI);
        $peringatan = array_filter($hasil, fn (array $p) => $p['status'] === OperationalReadiness::PERINGATAN);
        $beres = array_filter($hasil, fn (array $p) => $p['status'] === OperationalReadiness::BERES);

        if ($penghalang !== []) {
            $this->newLine();
            $this->error('MENGHALANGI OPERASI ('.count($penghalang).')');

            foreach ($penghalang as $p) {
                $this->line('  <fg=red>x</> '.$p['judul']);
                $this->line('    '.$p['akibat']);
            }
        }

        if ($peringatan !== []) {
            $this->newLine();
            $this->warn('PERLU DIPUTUSKAN RSP UI ('.count($peringatan).')');

            foreach ($peringatan as $p) {
                $this->line('  <fg=yellow>!</> '.$p['judul']);
                $this->line('    '.$p['akibat']);
            }
        }

        if ($this->option('semua') && $beres !== []) {
            $this->newLine();
            $this->info('SUDAH BERES ('.count($beres).')');

            foreach ($beres as $p) {
                $this->line('  <fg=green>v</> '.$p['judul']);
            }
        }

        $this->newLine();

        if ($penghalang !== []) {
            $this->error('Sistem BELUM bisa dipakai operasional: '.count($penghalang).' penghalang.');

            return self::FAILURE;
        }

        if ($peringatan !== []) {
            $this->warn('Sistem bisa dipakai, tapi '.count($peringatan).' hal masih menunggu keputusan RSP UI.');

            return self::SUCCESS;
        }

        $this->info('Seluruh pemeriksaan kesiapan lolos.');

        return self::SUCCESS;
    }
}
