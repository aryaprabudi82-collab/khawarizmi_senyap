<?php

namespace App\Modules\Identity\Console\Commands;

use App\Modules\Identity\Models\Patient;
use App\Modules\Identity\Services\HsnPatientMigrator;
use App\Modules\Identity\Services\PatientRegistry;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Memigrasikan pasien dari ekspor CSV HSN ke identity.patients.
 *
 * NOMOR REKAM MEDIS DIPAKAI APA ADANYA, bukan dialokasikan ulang. Nomor rekam
 * medis tercetak di berkas fisik, kartu pasien, surat rujukan, dan hasil
 * laboratorium yang sudah beredar bertahun-tahun. Mengalokasikan nomor baru
 * berarti seluruh arsip kertas rumah sakit tidak lagi cocok dengan sistemnya.
 *
 * KARENA ITU SEQUENCE HARUS DIMAJUKAN. HSN memakai 00000001–00371690;
 * identity.number_sequences masih di angka kecil. Tanpa dimajukan, pasien baru
 * pertama yang mendaftar akan mendapat nomor yang sudah dipakai pasien HSN —
 * dan bentroknya baru ketahuan saat INSERT gagal di loket, di depan pasien.
 *
 * DIJALANKAN BERULANG KALI AMAN. Pasien yang nomor rekam medisnya sudah ada
 * dilewati, bukan digandakan atau ditimpa.
 */
class MigrasiPasienHsn extends Command
{
    protected $signature = 'identity:migrasi-pasien-hsn
        {--berkas= : Path CSV Antrian HSN; bawaan database/HSN/Antrian_202604231509.csv}
        {--lihat-saja : Tampilkan ringkasan tanpa menulis apa pun}
        {--batas-baris= : Batasi jumlah BARIS CSV yang dibaca (untuk uji coba cepat)}';

    protected $description = 'Memigrasikan pasien dari ekspor CSV HSN ke identity.patients';

    public function handle(PatientRegistry $registry): int
    {
        $berkas = $this->option('berkas')
            ?: database_path('HSN/Antrian_202604231509.csv');

        if (! is_file($berkas)) {
            $this->error("Berkas tidak ditemukan: {$berkas}");

            return self::FAILURE;
        }

        $lihatSaja = (bool) $this->option('lihat-saja');
        $batasBaris = $this->option('batas-baris') !== null ? (int) $this->option('batas-baris') : null;

        /*
         * 348.880 pasien dikumpulkan di memori untuk mencari nama terpanjang
         * dan tanggal kunjungan paling awal per PersonKey — itu tidak bisa
         * dihindari tanpa mengurutkan berkas 14 GB lebih dulu. Empat field
         * pendek per pasien muat di bawah 512 MB; bawaan PHP 128 MB tidak.
         */
        ini_set('memory_limit', '1G');

        $migrator = new HsnPatientMigrator($berkas);

        $this->info('Membaca '.basename($berkas).' — ini memakan waktu, berkasnya besar.');

        $statistik = [
            'dibaca' => 0,
            'dimuat' => 0,
            'dilewati_sudah_ada' => 0,
            'gagal' => 0,
            'sex_L' => 0,
            'sex_P' => 0,
            'sex_null' => 0,
        ];

        $galat = [];
        $mrnTertinggi = 0;

        foreach ($migrator->pasienUnik($batasBaris) as $pasien) {
            $statistik['dibaca']++;

            $mrn = $pasien['medical_record_number'];

            if (ctype_digit($mrn)) {
                $mrnTertinggi = max($mrnTertinggi, (int) $mrn);
            }

            match ($pasien['sex']) {
                'L' => $statistik['sex_L']++,
                'P' => $statistik['sex_P']++,
                default => $statistik['sex_null']++,
            };

            if ($lihatSaja) {
                if ($statistik['dibaca'] <= 5) {
                    $this->line(sprintf(
                        '   %s | %-30s | sex=%-4s | %s',
                        $mrn,
                        mb_substr($pasien['name'], 0, 30),
                        $pasien['sex'] ?? 'NULL',
                        $pasien['sumber_sex'],
                    ));
                }

                continue;
            }

            if (Patient::query()->where('medical_record_number', $mrn)->exists()) {
                $statistik['dilewati_sudah_ada']++;

                continue;
            }

            try {
                $registry->register([
                    'medical_record_number' => $mrn,
                    'name' => $pasien['name'],
                    'sex' => $pasien['sex'],
                    'registered_on' => $pasien['registered_on'],
                ]);

                $statistik['dimuat']++;
            } catch (Throwable $e) {
                $statistik['gagal']++;

                if (count($galat) < 10) {
                    $galat[] = $mrn.': '.$e->getMessage();
                }
            }

            if ($statistik['dibaca'] % 25000 === 0) {
                $this->line('   ...'.number_format($statistik['dibaca']).' pasien diproses');
            }
        }

        $this->ringkasan($statistik, $galat, $lihatSaja);

        if (! $lihatSaja && $statistik['dimuat'] > 0) {
            $this->majukanSequence($mrnTertinggi);
        }

        return self::SUCCESS;
    }

    /**
     * Memajukan penomoran rekam medis melewati nomor tertinggi dari HSN.
     *
     * Dipanggil SESUDAH pemuatan, bukan sebelumnya: kalau migrasi gagal di
     * tengah, sequence yang sudah terlanjur melompat akan menyisakan jurang
     * nomor yang tidak pernah dipakai siapa pun.
     */
    private function majukanSequence(int $mrnTertinggi): void
    {
        if ($mrnTertinggi <= 0) {
            return;
        }

        $sekarang = (int) (DB::table('identity.number_sequences')
            ->where('prefix', 'default')
            ->value('last_number') ?? 0);

        if ($sekarang >= $mrnTertinggi) {
            $this->line("Sequence sudah di {$sekarang}, melewati {$mrnTertinggi} — tidak diubah.");

            return;
        }

        DB::table('identity.number_sequences')->updateOrInsert(
            ['prefix' => 'default'],
            ['last_number' => $mrnTertinggi, 'updated_at' => now()],
        );

        $this->info("Sequence nomor rekam medis dimajukan: {$sekarang} → {$mrnTertinggi}.");
        $this->line('Pasien baru berikutnya akan mendapat '.str_pad((string) ($mrnTertinggi + 1), 8, '0', STR_PAD_LEFT).'.');
    }

    /**
     * @param  array<string, int>  $s
     * @param  list<string>  $galat
     */
    private function ringkasan(array $s, array $galat, bool $lihatSaja): void
    {
        $this->newLine();
        $this->line('Pasien unik dibaca   : '.number_format($s['dibaca']));

        if (! $lihatSaja) {
            $this->line('Dimuat               : '.number_format($s['dimuat']));
            $this->line('Dilewati (sudah ada) : '.number_format($s['dilewati_sudah_ada']));
            $this->line('Gagal                : '.number_format($s['gagal']));
        }

        $this->newLine();
        $this->line('Jenis kelamin:');
        $this->line('   Laki-laki (sufiks TN)     : '.number_format($s['sex_L']));
        $this->line('   Perempuan (sufiks NY/NN)  : '.number_format($s['sex_P']));
        $this->line('   Belum diketahui           : '.number_format($s['sex_null']));

        $total = $s['sex_L'] + $s['sex_P'] + $s['sex_null'];

        if ($total > 0) {
            $this->line('   → tertentukan: '.round(($s['sex_L'] + $s['sex_P']) / $total * 100, 1).'%');
        }

        if ($galat !== []) {
            $this->newLine();
            $this->warn('Contoh kegagalan:');

            foreach ($galat as $g) {
                $this->line('   '.$g);
            }
        }

        if ($s['sex_null'] > 0 && ! $lihatSaja) {
            $this->newLine();
            $this->warn(
                number_format($s['sex_null']).' pasien masuk TANPA jenis kelamin. '
                .'Nilainya NULL, bukan ditebak — dan itu disengaja: jenis kelamin yang salah '
                .'menggeser rentang rujukan lab dan perhitungan dosis tanpa memunculkan galat. '
                .'Lengkapi lewat ekspor ulang dari tim AFYA (tabel AfyaMobile_SisUser, _Member_, '
                .'atau BedManagement) atau pengisian manual petugas rekam medis.'
            );
        }
    }
}
