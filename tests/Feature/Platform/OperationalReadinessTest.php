<?php

namespace Tests\Feature\Platform;

use App\Modules\Billing\Models\CashierShift;
use App\Modules\Platform\Models\Institution;
use App\Modules\Platform\Services\OperationalReadiness;
use App\Modules\Platform\Services\ScheduledTaskLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Pemeriksaan kesiapan operasional.
 *
 * MENGAPA UJI INI ADA. Laporan kesiapan yang salah lebih berbahaya daripada
 * tidak ada laporan sama sekali: yang membacanya berhenti memeriksa sendiri.
 * Kalau `siap:periksa` menyatakan "seluruh pemeriksaan lolos" padahal shift
 * kasir belum ada, RSP UI akan membuka layanan lalu menemukannya di kasir
 * pada sore hari pertama.
 *
 * Yang dikunci di sini justru arah yang paling mudah salah: laporan yang
 * terlalu OPTIMIS. Karena itu tiap pemeriksaan diuji dua kali — saat
 * kosong ia harus berteriak, dan saat terisi ia harus diam.
 */
class OperationalReadinessTest extends TestCase
{
    use RefreshDatabase;

    private OperationalReadiness $siap;

    protected function setUp(): void
    {
        parent::setUp();

        $this->siap = app(OperationalReadiness::class);
    }

    /** @return array<string, array{judul: string, status: string, akibat: string}> */
    private function hasil(): array
    {
        $peta = [];

        foreach ($this->siap->check() as $p) {
            $peta[$p['judul']] = $p;
        }

        return $peta;
    }

    #[Test]
    public function sistem_kosong_dilaporkan_belum_siap_bukan_lolos(): void
    {
        $hasil = $this->hasil();

        /*
         * Dua hal yang benar-benar MENGHALANGI, bukan sekadar kurang rapi.
         * Keduanya sengaja dibedakan dari peringatan: alur kerjanya
         * benar-benar tidak bisa dijalankan.
         */
        $this->assertSame(OperationalReadiness::MENGHALANGI,
            $hasil['Identitas rumah sakit']['status']);

        $this->assertSame(OperationalReadiness::MENGHALANGI,
            $hasil['Shift kasir']['status']);

        // Dan akibatnya disebut, bukan cuma statusnya — yang membaca laporan
        // ini adalah orang yang harus memutuskan mana dikerjakan dulu.
        $this->assertStringContainsString('TIDAK BISA dijalankan',
            $hasil['Shift kasir']['akibat']);
    }

    #[Test]
    public function penghalang_hilang_setelah_diisi(): void
    {
        Institution::query()->create(['id' => Institution::ID, 'name' => 'RSP Universitas Indonesia']);

        CashierShift::query()->create([
            'code' => 'PAGI', 'name' => 'Pagi',
            'start_time' => '07:00', 'end_time' => '14:00',
            'crosses_midnight' => false, 'is_active' => true,
        ]);

        $hasil = $this->hasil();

        $this->assertSame(OperationalReadiness::BERES, $hasil['Identitas rumah sakit']['status']);
        $this->assertSame(OperationalReadiness::BERES, $hasil['Shift kasir']['status']);
    }

    #[Test]
    public function kriteria_zis_tidak_dilaporkan_beres_hanya_karena_asnaf_terisi(): void
    {
        /*
         * JEBAKAN YANG NYARIS TERJADI. `philanthropy.assessment_criteria`
         * TIDAK kosong — delapan golongan asnaf sudah diseed sejak migrasi
         * karena ditetapkan di luar rumah sakit (At-Taubah 60). Pemeriksaan
         * yang cuma menghitung baris tabel akan melaporkan "beres" padahal
         * lima belas kategori lainnya — penghasilan, dinding rumah, lantai —
         * masih kosong dan pertanyaannya tidak akan muncul di formulir
         * survei sama sekali.
         */
        $this->assertGreaterThan(0,
            DB::table('philanthropy.assessment_criteria')->count(),
            'Prasyarat: asnaf memang sudah terisi, itu yang membuat jebakannya nyata.');

        $hasil = $this->hasil();

        $this->assertSame(OperationalReadiness::PERINGATAN,
            $hasil['Kriteria kelayakan ZIS']['status']);

        $this->assertStringContainsString('15 dari 15',
            $hasil['Kriteria kelayakan ZIS']['akibat']);
    }

    #[Test]
    public function pengaturan_yang_menyentuh_tagihan_dipisahkan_dari_yang_umum(): void
    {
        $hasil = $this->hasil();

        /*
         * Dipisah karena akibatnya berbeda jenis. Nama aplikasi yang belum
         * diisi cuma jelek dipandang; tarif embalase yang belum ditetapkan
         * berarti obat tertagih TANPA embalase setiap hari — dan tidak ada
         * galat yang menandainya. Menggabungkan keduanya dalam satu baris
         * membuat yang kedua tenggelam.
         */
        $this->assertSame(OperationalReadiness::PERINGATAN,
            $hasil['Pengaturan yang menyentuh tagihan']['status']);

        $this->assertStringContainsString('embalase',
            $hasil['Pengaturan yang menyentuh tagihan']['akibat']);

        $this->assertStringContainsString('Kosong BUKAN nol',
            $hasil['Pengaturan yang menyentuh tagihan']['akibat']);

        // Yang umum tidak boleh ikut menyeret pengaturan tagihan ke dalamnya.
        $this->assertStringNotContainsString('embalase',
            $hasil['Pengaturan umum']['akibat']);
    }

    #[Test]
    public function runway_partisi_yang_menipis_dilaporkan_sebagai_penghalang(): void
    {
        // Saat baru dipasang, runway 23 bulan — sehat.
        $this->assertSame(OperationalReadiness::BERES, $this->hasil()['Partisi tabel besar']['status']);

        $this->travelTo(now()->addMonths(23));

        $hasil = $this->hasil();

        /*
         * Runway habis BUKAN cuma soal partisi — ia juga pertanda bahwa
         * `schedule:run` di cron tidak berjalan, karena kalau berjalan
         * runway-nya tidak akan pernah menipis. Satu gejala, dua sebab, dan
         * keduanya disebut dalam pesannya.
         */
        $this->assertSame(OperationalReadiness::MENGHALANGI, $hasil['Partisi tabel besar']['status']);
        $this->assertStringContainsString('TIDAK berjalan', $hasil['Partisi tabel besar']['akibat']);
    }

    #[Test]
    public function syarat_kesiapan_dikumpulkan_dari_konteks_pemiliknya(): void
    {
        /*
         * Versi pertama OperationalReadiness mengueri tabel lima konteks lain
         * langsung, dan uji batas konteks menangkapnya. Uji ini menjaga
         * perbaikannya: butir kesiapan harus tetap SAMPAI walau kelasnya
         * tidak lagi tahu isi tabel siapa pun.
         *
         * Kalau pendaftaran lewat tag container patah — salah nama kelas,
         * provider tidak jalan, konvensi berubah — laporannya akan diam-diam
         * kehilangan butir dan menyatakan sistem lebih siap daripada
         * sebenarnya. Itu arah kegagalan yang paling berbahaya di sini.
         */
        $judul = array_keys($this->hasil());

        foreach ([
            'Ruang operasi',                    // organization
            'Shift kasir',                      // billing
            'Waktu makan pasien',               // kitchen
            'Area & kelompok risiko ICRA',      // quality
            'Kriteria kelayakan ZIS',           // philanthropy
        ] as $butir) {
            $this->assertContains($butir, $judul,
                "Butir kesiapan '{$butir}' hilang dari laporan. Konteks pemiliknya tidak "
                .'terdaftar lewat tag kesiapan — dan laporan yang kehilangan butir '
                .'menyatakan sistem lebih siap daripada sebenarnya.');
        }
    }

    #[Test]
    public function butir_kesiapan_wajib_menyebut_akibat_bukan_mengulang_judul(): void
    {
        foreach ($this->siap->check() as $butir) {
            /*
             * Yang membaca laporan ini adalah orang yang harus memutuskan
             * mana dikerjakan lebih dulu. "Belum diisi" tidak membantunya
             * memutuskan apa pun; yang membantu adalah tahu bahwa kasir
             * TIDAK BISA menutup shift sama sekali.
             */
            if ($butir['status'] === OperationalReadiness::BERES) {
                continue;
            }

            $this->assertGreaterThan(80, strlen($butir['akibat']),
                "Butir '{$butir['judul']}' berstatus {$butir['status']} tapi akibatnya "
                .'tidak dijelaskan: "'.$butir['akibat'].'"');

            /*
             * Sengaja TIDAK melarang akibat menyebut judulnya. Percobaan
             * pertama melarangnya, dan aturannya keliru: butir "Identitas
             * rumah sakit" menyebut "Pengaturan Aplikasi > Identitas Rumah
             * Sakit" sebagai JALAN MEMPERBAIKINYA — itu justru yang paling
             * berguna bagi yang membaca. Panjang minimal di atas sudah cukup
             * menangkap akibat yang cuma mengulang judul, tanpa melarang
             * kalimat yang menyebut menunya.
             */
        }
    }

    #[Test]
    public function cron_yang_belum_pernah_dipasang_terdeteksi_sebagai_penghalang(): void
    {
        /*
         * KETERGANTUNGAN PALING SENYAP DI SELURUH SISTEM. Perawatan partisi
         * menggantung pada satu baris cron di server aplikasi, dan kalau
         * baris itu tidak dipasang TIDAK ADA GALAT APA PUN yang muncul —
         * aplikasi tetap melayani pasien seperti biasa. Yang berhenti cuma
         * perawatannya, dan akibatnya baru terasa dua tahun kemudian sebagai
         * partisi yang habis.
         */
        $butir = $this->hasil()['Perawatan terjadwal (cron)'];

        $this->assertSame(OperationalReadiness::MENGHALANGI, $butir['status']);
        $this->assertStringContainsString('BELUM PERNAH', $butir['akibat']);

        // Dan cara memperbaikinya disebut, bukan cuma keluhannya.
        $this->assertStringContainsString('schedule:run', $butir['akibat']);
    }

    #[Test]
    public function cron_yang_pernah_jalan_lalu_berhenti_dibedakan_dari_yang_belum_pernah(): void
    {
        $jejak = app(ScheduledTaskLog::class);

        $jejak->record(ScheduledTaskLog::PARTISI, 'uji');

        $this->assertSame(OperationalReadiness::BERES,
            $this->hasil()['Perawatan terjadwal (cron)']['status']);

        /*
         * Dua hari kemudian tanpa jalan lagi: cron PERNAH dipasang lalu
         * berhenti. Itu tindakan yang berbeda dari "belum dipasang" — yang
         * satu memasang baris baru, yang lain mencari kenapa yang ada
         * berhenti — dan pesan yang menyamakannya membuat orang mencari di
         * tempat yang salah.
         */
        $this->travelTo(now()->addHours(ScheduledTaskLog::BATAS_JAM + 1));

        $butir = $this->hasil()['Perawatan terjadwal (cron)'];

        $this->assertSame(OperationalReadiness::MENGHALANGI, $butir['status']);
        $this->assertStringContainsString('PERNAH dipasang lalu berhenti', $butir['akibat']);
        $this->assertStringNotContainsString('BELUM PERNAH', $butir['akibat']);
    }

    #[Test]
    public function satu_malam_terlewat_belum_dianggap_cron_mati(): void
    {
        app(ScheduledTaskLog::class)->record(ScheduledTaskLog::PARTISI, 'uji');

        /*
         * Batasnya dua hari, bukan satu. Tugas harian yang gagal sekali —
         * server dinyalakan ulang tepat pada jam jadwalnya, pemeliharaan
         * singkat — belum berarti cronnya mati. Peringatan yang berbunyi
         * karena satu malam terlewat akan cepat diabaikan orang, dan
         * peringatan yang diabaikan sama saja dengan tidak ada.
         */
        $this->travelTo(now()->addHours(25));

        $this->assertSame(OperationalReadiness::BERES,
            $this->hasil()['Perawatan terjadwal (cron)']['status']);
    }

    #[Test]
    public function perawatan_mencatat_jejaknya_walau_tidak_ada_partisi_baru(): void
    {
        $jejak = app(ScheduledTaskLog::class);

        $this->assertNull($jejak->lastRun(ScheduledTaskLog::PARTISI));

        $this->artisan('partisi:pastikan')->assertSuccessful();

        /*
         * DICATAT WALAU TIDAK ADA YANG DIBUAT. Yang hendak dibuktikan
         * catatan ini adalah cron masih berjalan — dan hari-hari saat tidak
         * ada partisi yang perlu dibuat justru mayoritasnya. Mencatat hanya
         * saat ada perubahan berarti cron yang sehat tampak mati selama
         * berbulan-bulan.
         */
        $this->assertNotNull($jejak->lastRun(ScheduledTaskLog::PARTISI));
        $this->assertStringContainsString('Runway sudah cukup',
            (string) $jejak->summary(ScheduledTaskLog::PARTISI));
    }

    #[Test]
    public function perintah_keluar_dengan_kode_gagal_saat_ada_penghalang(): void
    {
        // Supaya bisa dipasang sebagai gerbang sebelum penggelaran: yang
        // membacanya mesin, dan mesin cuma mengerti kode keluar.
        $this->artisan('siap:periksa')->assertFailed();

        Institution::query()->create(['id' => Institution::ID, 'name' => 'RSP UI']);
        CashierShift::query()->create([
            'code' => 'PAGI', 'name' => 'Pagi', 'start_time' => '07:00', 'end_time' => '14:00',
            'crosses_midnight' => false, 'is_active' => true,
        ]);

        // Cron juga penghalang, dan itu memang benar: sistem yang perawatan
        // terjadwalnya belum pernah berjalan belum siap digelar.
        app(ScheduledTaskLog::class)->record(ScheduledTaskLog::PARTISI, 'uji');

        /*
         * Peringatan TIDAK menggagalkan. Kalau ia menggagalkan, RSP UI tidak
         * akan pernah bisa menggelar sistem sampai seluruh kosakata diskresi
         * mereka selesai disusun — dan itu menahan pelayanan demi kerapian.
         */
        $this->artisan('siap:periksa')->assertSuccessful();
    }
}
