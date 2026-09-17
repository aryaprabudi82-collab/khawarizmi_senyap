<?php

namespace App\Modules\Identity\Console\Commands;

use App\Modules\Identity\Models\Patient;
use App\Modules\Identity\Services\HsnPatientEnricher;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Melengkapi demografi pasien dari `pasien.csv`, dan memuat pasien yang
 * belum pernah masuk.
 *
 * MENGAPA TERPISAH DARI identity:migrasi-pasien-hsn. Perintah itu memuat
 * pasien dari tabel ANTREAN, satu-satunya sumber berjembatan pada ekspor
 * pertama — dan antrean tidak menyimpan NIK, tanggal lahir, alamat, maupun
 * telepon. Perintah ini mengisi kekosongan itu dari tabel pasien yang
 * sesungguhnya, yang baru tersedia belakangan.
 *
 * DUA PEKERJAAN SEKALIGUS, DAN ITU DISENGAJA:
 *   1. MEMPERBARUI 348.880 pasien yang sudah ada — mengisi kolom yang kosong.
 *   2. MEMBUAT 22.829 pasien yang belum pernah masuk — mereka terdaftar tapi
 *      belum pernah lewat antrean poli. Dibiarkan di luar, saat mereka datang
 *      berobat petugas akan membuatkan nomor rekam medis BARU, dan satu orang
 *      berakhir dengan dua nomor.
 *
 * JENIS KELAMIN DARI SINI MENGALAHKAN TEBAKAN SUFIKS NAMA. Migrasi pertama
 * menyimpulkannya dari ". NY"/". TN" dengan akurasi 99,97% — cukup baik, tapi
 * tetap kesimpulan. `pasien.csv` membawa jenis kelamin sungguhan 100% terisi,
 * dan nilai otoritatif selalu mengalahkan nilai yang disimpulkan mesin.
 *
 * TIDAK LEWAT PatientRegistry::register() untuk pasien yang sudah ada:
 * metode itu MEMBUAT, dan melempar DuplicatePatientException begitu NIK-nya
 * ketemu. Yang dibutuhkan di sini pembaruan. Pasien BARU tetap lewat jalur
 * biasa lewat model yang sama, dengan nomor rekam medis dari sumber.
 */
class LengkapiPasienHsn extends Command
{
    protected $signature = 'identity:lengkapi-pasien-hsn
        {--berkas= : Path pasien.csv; bawaan database/HSN/pasien.csv}
        {--lihat-saja : Tampilkan ringkasan tanpa menulis apa pun}
        {--batas-baris= : Batasi jumlah baris yang dibaca (untuk uji coba)}
        {--tanpa-pasien-baru : Hanya melengkapi yang sudah ada, tidak membuat pasien baru}';

    protected $description = 'Melengkapi NIK, tanggal lahir, alamat, dan telepon pasien dari pasien.csv';

    /** Kolom yang diisi dari sumber. */
    private const KOLOM = [
        'nik', 'sex', 'birth_place', 'birth_date', 'address',
        'blood_type', 'occupation', 'marital_status', 'religion',
        'phone', 'education',
    ];

    public function handle(): int
    {
        $berkas = $this->option('berkas') ?: database_path('HSN/pasien.csv');

        if (! is_file($berkas)) {
            $this->error("Berkas tidak ditemukan: {$berkas}");

            return self::FAILURE;
        }

        $lihatSaja = (bool) $this->option('lihat-saja');
        $batas = $this->option('batas-baris') !== null ? (int) $this->option('batas-baris') : null;
        $tanpaBaru = (bool) $this->option('tanpa-pasien-baru');

        ini_set('memory_limit', '1G');

        /*
         * Seluruh nomor rekam medis yang sudah ada ditarik sekali di awal.
         * Alternatifnya satu SELECT per baris — 371.719 query bolak-balik ke
         * basis data untuk pertanyaan yang jawabannya tidak berubah.
         */
        $this->info('Membaca nomor rekam medis yang sudah ada...');

        $adaSebelumnya = DB::table('identity.patients')
            ->pluck('id', 'medical_record_number')
            ->all();

        $this->line('   '.number_format(count($adaSebelumnya)).' pasien sudah ada di basis data.');
        $this->newLine();

        $enricher = new HsnPatientEnricher($berkas);

        $s = [
            'dibaca' => 0, 'diperbarui' => 0, 'dibuat' => 0,
            'gagal' => 0, 'tak_berubah' => 0,
            'nik_diisi' => 0, 'nik_ditolak_format' => 0, 'nik_ditolak_ganda' => 0,
            'sex_diisi' => 0, 'sex_dikoreksi' => 0,
            'tgl_lahir' => 0, 'alamat' => 0, 'telepon' => 0,
        ];

        $galat = [];
        $nikGanda = [];

        foreach ($enricher->pasien($batas) as $p) {
            $s['dibaca']++;

            if ($p['nik'] !== null) {
                $s['nik_diisi']++;
            } elseif ($p['nik_ditolak'] === 'bukan-16-digit') {
                $s['nik_ditolak_format']++;
            } elseif ($p['nik_ditolak'] !== null) {
                $s['nik_ditolak_ganda']++;

                if (count($nikGanda) < 5000) {
                    $nikGanda[] = $p['medical_record_number'].';'.$p['nik_ditolak'];
                }
            }

            if ($p['sex'] !== null) {
                $s['sex_diisi']++;
            }

            if ($p['birth_date'] !== null) {
                $s['tgl_lahir']++;
            }

            if ($p['address'] !== null) {
                $s['alamat']++;
            }

            if ($p['phone'] !== null) {
                $s['telepon']++;
            }

            if ($lihatSaja) {
                if ($s['dibaca'] <= 3) {
                    /*
                     * NAMA DAN NIK TIDAK DICETAK APA ADANYA. Keluaran perintah
                     * berakhir di log terminal, tangkapan layar, dan tiket
                     * dukungan — tempat yang tidak dikendalikan siapa pun.
                     * Yang perlu dilihat saat menguji coba adalah apakah
                     * kolomnya TERBACA, bukan siapa orangnya.
                     */
                    $this->line(sprintf(
                        '   %s | nama %d huruf | nik=%s | %s | lahir %s',
                        $p['medical_record_number'],
                        mb_strlen($p['name']),
                        $p['nik'] === null ? '(kosong)' : substr($p['nik'], 0, 4).'************',
                        $p['sex'] ?? '?',
                        $p['birth_date'] === null ? '?' : substr($p['birth_date'], 0, 4),
                    ));
                }

                continue;
            }

            try {
                $id = $adaSebelumnya[$p['medical_record_number']] ?? null;

                if ($id !== null) {
                    $s[$this->perbarui($id, $p) ? 'diperbarui' : 'tak_berubah']++;
                } elseif (! $tanpaBaru) {
                    $this->buat($p);
                    $s['dibuat']++;
                }
            } catch (Throwable $e) {
                $s['gagal']++;

                if (count($galat) < 10) {
                    $galat[] = $p['medical_record_number'].': '.mb_substr($e->getMessage(), 0, 120);
                }
            }

            if ($s['dibaca'] % 25000 === 0) {
                $this->line('   ...'.number_format($s['dibaca']).' baris diproses');
            }
        }

        $this->ringkasan($s, $galat, $lihatSaja);

        if (! $lihatSaja && $nikGanda !== []) {
            $this->tulisLaporanNikGanda($nikGanda);
        }

        return self::SUCCESS;
    }

    /**
     * Mengisi kolom yang KOSONG, tidak menimpa yang sudah terisi.
     *
     * Kecuali `sex`: nilai yang ada berasal dari tebakan sufiks nama, dan
     * sumber ini otoritatif. Yang tidak boleh ditimpa adalah data yang
     * dimasukkan petugas — dan itu belum ada di tahap ini.
     *
     * @param  array<string, mixed>  $p
     */
    private function perbarui(int $id, array $p): bool
    {
        $pasien = Patient::query()->find($id);

        if ($pasien === null) {
            return false;
        }

        $ubah = [];

        foreach (self::KOLOM as $kolom) {
            $baru = $p[$kolom] ?? null;

            if ($baru === null) {
                continue;
            }

            $lama = $pasien->{$kolom};

            if ($kolom === 'sex') {
                if ($lama !== $baru) {
                    $ubah['sex'] = $baru;
                }

                continue;
            }

            if ($lama === null || $lama === '') {
                $ubah[$kolom] = $baru;
            }
        }

        if ($ubah === []) {
            return false;
        }

        $pasien->forceFill($ubah)->save();

        return true;
    }

    /**
     * @param  array<string, mixed>  $p
     */
    private function buat(array $p): void
    {
        $data = ['medical_record_number' => $p['medical_record_number'], 'name' => $p['name']];

        foreach (self::KOLOM as $kolom) {
            if (($p[$kolom] ?? null) !== null) {
                $data[$kolom] = $p[$kolom];
            }
        }

        // registered_on NOT NULL. Tanggal daftar dari sumber bila ada;
        // kalau tidak, tanggal lahir — bukan hari ini, yang akan membuat
        // pasien lama tampak mendaftar pada hari migrasi dijalankan.
        $data['registered_on'] = $p['registered_on'] ?? $p['birth_date'] ?? now()->toDateString();

        Patient::query()->create($data);
    }

    /** @param list<string> $baris */
    private function tulisLaporanNikGanda(array $baris): void
    {
        $path = storage_path('app/nik-ganda-'.date('Ymd-His').'.csv');

        file_put_contents(
            $path,
            "medical_record_number;alasan\n".implode("\n", $baris)."\n"
        );

        $this->newLine();
        $this->warn('Laporan NIK ganda: '.$path);
        $this->line('   Berisi nomor rekam medis yang NIK-nya tidak diisikan karena sudah');
        $this->line('   dipakai pasien lain. Perlu ditinjau petugas rekam medis: lazimnya');
        $this->line('   satu orang terdaftar dua kali, atau NIK orang tua dipakai anaknya.');
    }

    /**
     * @param  array<string, int>  $s
     * @param  list<string>  $galat
     */
    private function ringkasan(array $s, array $galat, bool $lihatSaja): void
    {
        $this->newLine();
        $this->line('Baris dibaca         : '.number_format($s['dibaca']));

        if (! $lihatSaja) {
            $this->line('Pasien diperbarui    : '.number_format($s['diperbarui']));
            $this->line('Pasien baru dibuat   : '.number_format($s['dibuat']));
            $this->line('Tidak berubah        : '.number_format($s['tak_berubah']));
            $this->line('Gagal                : '.number_format($s['gagal']));
        }

        $this->newLine();
        $this->line('Data yang tersedia di sumber:');
        $this->line('   NIK diisikan           : '.number_format($s['nik_diisi']));
        $this->line('   NIK ditolak (format)   : '.number_format($s['nik_ditolak_format']).'  (nomor SIM/NPM/KTM, bukan NIK)');
        $this->line('   NIK ditolak (ganda)    : '.number_format($s['nik_ditolak_ganda']).'  (sudah dipakai pasien lain)');
        $this->line('   Jenis kelamin          : '.number_format($s['sex_diisi']));
        $this->line('   Tanggal lahir          : '.number_format($s['tgl_lahir']));
        $this->line('   Alamat                 : '.number_format($s['alamat']));
        $this->line('   Telepon                : '.number_format($s['telepon']));

        if ($galat !== []) {
            $this->newLine();
            $this->warn('Contoh kegagalan:');

            foreach ($galat as $g) {
                $this->line('   '.$g);
            }
        }
    }
}
