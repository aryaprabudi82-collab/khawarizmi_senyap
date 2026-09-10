<?php

namespace App\Modules\Platform\Services;

use App\Modules\ModuleServiceProvider;
use App\Modules\ReadinessCheck;
use Illuminate\Support\Facades\DB;

/**
 * Apakah sistem sudah bisa dipakai operasional besok pagi.
 *
 * DIBEDAKAN TEGAS DARI "apakah kodenya benar". Dua ribuan uji di repositori
 * ini menjawab yang kedua; yang pertama bergantung pada keputusan yang hanya
 * bisa diambil RSP UI, dan yang sengaja TIDAK ditebak sistem ini.
 *
 * KELAS INI HANYA MENGUMPULKAN. Syarat kesiapan tiap konteks dimiliki
 * konteks itu sendiri lewat ReadinessCheck; di sini cuma ditambahkan syarat
 * yang memang milik platform — identitas institusi, pengaturan aplikasi,
 * partisi, dan keamanan produksi.
 *
 * Versi pertama kelas ini mengueri tabel lima konteks lain langsung, dan uji
 * batas konteks menangkapnya. Kesalahannya bukan sekadar teknis: pengetahuan
 * tentang apa yang membuat sebuah konteks siap adalah milik konteks itu, dan
 * daftar terpusat akan benar hari ini lalu diam-diam usang pada syarat
 * berikutnya yang ditambahkan orang lain.
 *
 * TIGA TINGKAT, DAN BEDANYA PENTING:
 *
 *  - MENGHALANGI: ada alur kerja yang sama sekali tidak bisa dijalankan.
 *    Bukan "kurang rapi" — kasir benar-benar tidak bisa menutup shift kalau
 *    tidak ada shift yang didefinisikan.
 *
 *  - PERINGATAN: alurnya jalan, tapi ada keputusan RSP UI yang belum
 *    diambil dan ketiadaannya punya akibat yang bisa disebut. Tarif embalase
 *    yang kosong tidak menggagalkan penyerahan obat; ia membuat obat
 *    tertagih tanpa embalase, diam-diam, setiap hari.
 *
 *  - BERES: sudah terisi.
 *
 * YANG SENGAJA TIDAK DIPERIKSA: apakah isinya BENAR. Sistem bisa memastikan
 * shift kasir ada; ia tidak bisa memastikan jamnya sesuai jam layanan
 * sungguhan. Pemeriksaan yang mengaku bisa menilai itu akan membuat orang
 * berhenti memeriksanya sendiri.
 */
class OperationalReadiness
{
    public const MENGHALANGI = ReadinessCheck::MENGHALANGI;

    public const PERINGATAN = ReadinessCheck::PERINGATAN;

    public const BERES = ReadinessCheck::BERES;

    public function __construct(
        private readonly PartitionManager $partisi,
        private readonly ScheduledTaskLog $jejak,
    ) {}

    /**
     * @return list<array{judul: string, status: string, akibat: string}>
     */
    public function check(): array
    {
        return array_merge(
            $this->periksaIdentitas(),
            $this->dariKonteksLain(),
            $this->periksaPengaturan(),
            $this->periksaPartisi(),
            $this->periksaPerawatanTerjadwal(),
            $this->periksaKeamananProduksi(),
        );
    }

    /**
     * Syarat kesiapan yang dilaporkan tiap bounded context sendiri.
     *
     * @return list<array{judul: string, status: string, akibat: string}>
     */
    private function dariKonteksLain(): array
    {
        $hasil = [];

        foreach (app()->tagged(ModuleServiceProvider::READINESS_TAG) as $pemeriksa) {
            /** @var ReadinessCheck $pemeriksa */
            foreach ($pemeriksa->readinessItems() as $butir) {
                $hasil[] = $butir;
            }
        }

        return $hasil;
    }

    /** @return list<array{judul: string, status: string, akibat: string}> */
    private function periksaIdentitas(): array
    {
        $ada = DB::table('platform.institution')->where('id', 1)->exists();

        return [[
            'judul' => 'Identitas rumah sakit',
            'status' => $ada ? self::BERES : self::MENGHALANGI,
            'akibat' => $ada
                ? 'Terisi.'
                : 'Nama, alamat, dan kode fasilitas rumah sakit belum diisi — dan tidak '
                  .'ada yang menebaknya. Seluruh kuitansi, surat keterangan, dan berkas '
                  .'yang dikirim ke sistem luar akan terbit tanpa identitas penerbitnya. '
                  .'Isi lewat Pengaturan Aplikasi > Identitas Rumah Sakit.',
        ]];
    }

    /** @return list<array{judul: string, status: string, akibat: string}> */
    private function periksaPengaturan(): array
    {
        $belum = DB::table('platform.settings')
            ->whereNull('value')->where('is_active', true)
            ->orderBy('key')->pluck('key')->all();

        /*
         * Pengaturan yang menyentuh UANG dipisahkan: kekosongannya bukan
         * ketidaknyamanan, ia langsung berakibat pada tagihan. Embalase yang
         * belum ditetapkan berarti obat tertagih tanpa embalase setiap hari,
         * dan tidak ada satu pun galat yang menandainya. Digabung jadi satu
         * baris dengan "nama aplikasi belum diisi", yang serius akan
         * tenggelam bersama yang sepele.
         */
        $uang = array_values(array_filter(
            $belum,
            fn ($k) => str_starts_with($k, 'farmasi:') || str_starts_with($k, 'billing:')
        ));

        $lain = array_values(array_diff($belum, $uang));

        return [
            [
                'judul' => 'Pengaturan yang menyentuh tagihan',
                'status' => $uang === [] ? self::BERES : self::PERINGATAN,
                'akibat' => $uang === []
                    ? 'Seluruhnya ditetapkan.'
                    : count($uang).' belum ditetapkan ('.implode(', ', $uang).'). Kosong '
                      .'BUKAN nol: selama belum ditetapkan, komponen biaya ini tidak ikut '
                      .'tertagih dan tidak ada galat yang menandainya.',
            ],
            [
                'judul' => 'Pengaturan umum',
                'status' => $lain === [] ? self::BERES : self::PERINGATAN,
                'akibat' => $lain === []
                    ? 'Seluruhnya ditetapkan.'
                    : count($lain).' belum ditetapkan ('.implode(', ', $lain).'). Tidak '
                      .'menghentikan pelayanan, tapi tiap satunya punya akibatnya sendiri: '
                      .'format nomor rekam medis yang belum ditetapkan membuat penomoran '
                      .'jatuh ke pola bawaan yang sulit diubah setelah ribuan pasien '
                      .'terdaftar, dan batas jam kamar inap yang kosong membuat perhitungan '
                      .'hari rawat memakai tengah malam — bukan jam yang disepakati.',
            ],
        ];
    }

    /** @return list<array{judul: string, status: string, akibat: string}> */
    private function periksaPartisi(): array
    {
        $kesehatan = $this->partisi->health();

        $runwayTerpendek = 99;
        $adaBarisDefault = false;

        foreach ($kesehatan['tabel'] as $t) {
            $runwayTerpendek = min($runwayTerpendek, $t['runway_bulan']);
            $adaBarisDefault = $adaBarisDefault || $t['baris_default'] > 0;
        }

        if ($adaBarisDefault) {
            return [[
                'judul' => 'Partisi tabel besar',
                'status' => self::MENGHALANGI,
                'akibat' => 'Sudah ada baris yang jatuh ke partisi DEFAULT. Partisi rentang '
                    .'untuk bulan-bulan itu tidak bisa lagi dibuat sebelum barisnya '
                    .'dipindahkan — dan pemindahannya mengunci tabel. Jalankan '
                    .'`php artisan partisi:periksa` untuk rinciannya.',
            ]];
        }

        return [[
            'judul' => 'Partisi tabel besar',
            'status' => $runwayTerpendek >= PartitionManager::RUNWAY_MINIMAL ? self::BERES : self::MENGHALANGI,
            'akibat' => $runwayTerpendek >= PartitionManager::RUNWAY_MINIMAL
                ? 'Runway terpendek '.$runwayTerpendek.' bulan.'
                : 'Runway tinggal '.$runwayTerpendek.' bulan. Kalau habis, baris baru jatuh '
                  .'ke partisi DEFAULT dan sistem melambat tanpa galat apa pun. Ini juga '
                  .'pertanda perawatan terjadwal (`schedule:run` di cron) TIDAK berjalan.',
        ]];
    }

    /**
     * Apakah cron `schedule:run` benar-benar berjalan.
     *
     * INILAH KETERGANTUNGAN PALING SENYAP DI SELURUH SISTEM. Perawatan
     * partisi — dan apa pun yang dijadwalkan sesudahnya — menggantung pada
     * SATU baris cron di server aplikasi. Kalau baris itu tidak dipasang,
     * atau hilang saat server dipindah, TIDAK ADA GALAT APA PUN yang muncul.
     * Aplikasi tetap melayani pasien seperti biasa; yang berhenti cuma
     * perawatannya, dan akibatnya baru terasa berbulan-bulan kemudian.
     *
     * Runway partisi yang menipis menandakan hal yang sama, tapi ia baru
     * berbunyi setelah hampir dua tahun. Ini berbunyi dalam dua hari.
     *
     * BELUM PERNAH JALAN dibedakan dari SUDAH LAMA TIDAK JALAN: yang pertama
     * berarti cronnya belum dipasang, yang kedua berarti pernah dipasang
     * lalu berhenti. Keduanya menuntut tindakan berbeda, dan pesan yang
     * menyamakannya membuat orang mencari di tempat yang salah.
     *
     * @return list<array{judul: string, status: string, akibat: string}>
     */
    private function periksaPerawatanTerjadwal(): array
    {
        $terakhir = $this->jejak->lastRun(ScheduledTaskLog::PARTISI);

        if ($terakhir === null) {
            return [[
                'judul' => 'Perawatan terjadwal (cron)',
                'status' => self::MENGHALANGI,
                'akibat' => 'Perawatan terjadwal BELUM PERNAH berjalan sama sekali — '
                    .'pertanda barisnya belum dipasang di cron server aplikasi. Tanpa itu '
                    .'partisi tidak pernah diperpanjang, dan dalam dua tahun seluruh baris '
                    .'baru jatuh ke partisi DEFAULT tanpa satu pun galat muncul. Pasang '
                    .'satu baris: setiap menit, jalankan `php artisan schedule:run` dari '
                    .'direktori aplikasi.',
            ]];
        }

        $segar = $this->jejak->isFresh(ScheduledTaskLog::PARTISI);

        return [[
            'judul' => 'Perawatan terjadwal (cron)',
            'status' => $segar ? self::BERES : self::MENGHALANGI,
            'akibat' => $segar
                ? 'Terakhir berjalan '.$terakhir->diffForHumans().'.'
                : 'Terakhir berjalan '.$terakhir->diffForHumans().', lewat dari batas wajar '
                  .'tugas harian. Cron PERNAH dipasang lalu berhenti — periksa apakah '
                  .'barisnya masih ada dan penggunanya masih bisa menjalankan artisan. '
                  .'Selama berhenti, partisi tidak diperpanjang dan tidak ada galat apa pun '
                  .'yang akan memberitahu.',
        ]];
    }

    /** @return list<array{judul: string, status: string, akibat: string}> */
    private function periksaKeamananProduksi(): array
    {
        $hasil = [];

        /*
         * Hanya berlaku saat APP_ENV=production. Di lingkungan pengembangan
         * debug memang harus menyala, dan memperingatkannya di sana cuma
         * melatih orang mengabaikan peringatan.
         */
        if (app()->environment('production')) {
            $hasil[] = [
                'judul' => 'APP_DEBUG di produksi',
                'status' => config('app.debug') ? self::MENGHALANGI : self::BERES,
                'akibat' => config('app.debug')
                    ? 'APP_DEBUG masih true di produksi. Setiap galat akan menampilkan '
                      .'jejak tumpukan berikut isi variabel — termasuk data pasien dan '
                      .'kredensial basis data — kepada siapa pun yang memicunya.'
                    : 'Nonaktif.',
            ];
        }

        $hasil[] = [
            'judul' => 'APP_KEY',
            'status' => config('app.key') ? self::BERES : self::MENGHALANGI,
            'akibat' => config('app.key')
                ? 'Terpasang.'
                : 'APP_KEY belum diset. Sesi dan data terenkripsi tidak bisa dibaca.',
        ];

        return $hasil;
    }
}
