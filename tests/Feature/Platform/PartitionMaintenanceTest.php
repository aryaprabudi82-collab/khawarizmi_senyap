<?php

namespace Tests\Feature\Platform;

use App\Modules\Platform\Services\PartitionManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

/**
 * Perawatan partisi bulanan.
 *
 * MENGAPA UJI INI ADA. Empat tabel terbesar sistem ini dipartisi per bulan,
 * tapi partisinya dibuat SEKALI saat migrasi sebanyak 24 bulan lalu satu
 * partisi DEFAULT. Pada RSP UI dengan 2.000 pasien sehari, keempatnya
 * tumbuh jutaan baris per tahun — dan begitu bulan ke-25 tiba tanpa ada
 * yang menambah partisi, seluruh baris baru jatuh ke DEFAULT.
 *
 * Yang terjadi kemudian BUKAN galat. Sistem cuma pelan-pelan melambat:
 * DEFAULT jadi satu tabel raksasa tanpa partisi, dan kueri yang seharusnya
 * menyentuh satu bulan mulai memindai seluruh riwayat. Tidak ada uji yang
 * gagal, tidak ada peringatan yang muncul; yang ada cuma keluhan bahwa
 * "sistemnya makin lambat" dua tahun setelah dipasang.
 */
class PartitionMaintenanceTest extends TestCase
{
    use RefreshDatabase;

    private PartitionManager $partisi;

    protected function setUp(): void
    {
        parent::setUp();

        $this->partisi = app(PartitionManager::class);
    }

    #[Test]
    public function seluruh_tabel_berpartisi_ditemukan_dari_katalog_bukan_daftar_tangan(): void
    {
        $induk = array_column($this->partisi->partitionedTables(), 'induk');

        /*
         * Ditemukan dari pg_class, bukan dari daftar yang ditulis tangan.
         * Daftar tangan akan benar hari ini dan diam-diam salah pada tabel
         * berpartisi kelima yang ditambahkan orang lain tahun depan — dan
         * salahnya baru terasa dua tahun kemudian, persis saat tidak ada
         * yang ingat daftar itu pernah ada.
         */
        $this->assertContains('billing.charge_lines', $induk);
        $this->assertContains('clinical.observations', $induk);
        $this->assertContains('pharmacy.stock_movements', $induk);
        $this->assertContains('platform.audit_logs', $induk);

        // Kolom partisinya ikut dibaca dari katalog, bukan ditebak dari nama.
        $peta = collect($this->partisi->partitionedTables())->pluck('kolom', 'induk');

        $this->assertSame('charged_at', $peta['billing.charge_lines']);
        $this->assertSame('observed_at', $peta['clinical.observations']);
        $this->assertSame('created_at', $peta['platform.audit_logs']);
    }

    #[Test]
    public function bulan_tercakup_dibaca_dari_batas_partisi_bukan_dari_namanya(): void
    {
        /*
         * Nama cuma kesepakatan; batas adalah kenyataan. Partisi yang
         * dinamai keliru tapi berbatas benar tetap harus terhitung — kalau
         * pembacaannya bersandar nama, satu partisi yang dibuat manual
         * dengan nama berbeda akan membuat sistem mengira runway-nya habis
         * lalu mencoba membuat partisi yang bertabrakan.
         */
        DB::statement("CREATE TABLE billing.charge_lines_dinamai_aneh
            PARTITION OF billing.charge_lines
            FOR VALUES FROM ('2029-01-01') TO ('2029-02-01')");

        $terakhir = $this->partisi->lastCoveredMonth('billing.charge_lines');

        $this->assertSame('2029-01', $terakhir->format('Y-m'));
    }

    #[Test]
    public function memastikan_runway_membuat_partisi_yang_kurang(): void
    {
        /*
         * Maju ke bulan ke-22 dari pemasangan — keadaan yang sesungguhnya
         * akan dihadapi RSP UI, dan yang selama ini tidak ada yang
         * mengurusnya. Migrasi menyiapkan 24 bulan; di titik ini tinggal
         * satu bulan tersisa dan tidak ada satu pun galat yang muncul.
         */
        $this->travelTo(Carbon::now()->addMonths(22));

        $sebelum = $this->partisi->runwayMonths('billing.charge_lines');

        $this->assertSame(1, $sebelum, 'Prasyarat: runway harus sudah menipis sebelum diuji.');

        $dibuat = $this->partisi->ensureRunway(PartitionManager::RUNWAY_BULAN);

        $sesudah = $this->partisi->runwayMonths('billing.charge_lines');

        $this->assertGreaterThan($sebelum, $sesudah);
        $this->assertGreaterThanOrEqual(PartitionManager::RUNWAY_BULAN, $sesudah);
        $this->assertNotEmpty($dibuat);

        // Keempat tabel ikut terawat, bukan cuma yang kebetulan diperiksa.
        foreach (['clinical.observations', 'pharmacy.stock_movements', 'platform.audit_logs'] as $lain) {
            $this->assertGreaterThanOrEqual(
                PartitionManager::RUNWAY_BULAN,
                $this->partisi->runwayMonths($lain),
                $lain.' tidak ikut terawat.'
            );
        }
    }

    #[Test]
    public function memastikan_runway_bersifat_idempoten(): void
    {
        $this->partisi->ensureRunway();

        // Menjalankannya lagi tidak boleh melakukan apa pun — perawatan
        // terjadwal berjalan setiap hari, dan yang berbuat sesuatu setiap
        // hari akan menumpuk partisi kosong tanpa henti.
        $kedua = $this->partisi->ensureRunway();

        $this->assertSame([], $kedua);
    }

    #[Test]
    public function runway_dihitung_dari_bulan_ini_bukan_dari_jumlah_partisi(): void
    {
        $runway = $this->partisi->runwayMonths('billing.charge_lines');

        // Migrasi membuat 24 bulan mulai bulan ini, jadi bulan terakhir yang
        // tercakup adalah 23 bulan dari sekarang.
        $this->assertSame(23, $runway);

        $this->travelTo(Carbon::now()->addMonths(22));

        // Waktu berjalan, runway menyusut — walau jumlah partisinya tidak
        // berubah sama sekali. Menghitung "berapa partisi yang ada" akan
        // menjawab 24 selamanya dan tidak pernah memicu peringatan.
        $this->assertSame(1, $this->partisi->runwayMonths('billing.charge_lines'));
    }

    #[Test]
    public function pemeriksaan_kesehatan_berteriak_saat_runway_menipis(): void
    {
        $this->assertTrue($this->partisi->health()['sehat']);

        $this->travelTo(Carbon::now()->addMonths(23));

        $hasil = $this->partisi->health();

        // Runway 0 bulan: bulan ini adalah yang terakhir tercakup. Itu sudah
        // gawat, bukan "masih aman satu bulan".
        $this->assertFalse($hasil['sehat']);

        $charge = collect($hasil['tabel'])->firstWhere('induk', 'billing.charge_lines');

        $this->assertSame(0, $charge['runway_bulan']);
        $this->assertFalse($charge['aman']);
    }

    #[Test]
    public function baris_yang_telanjur_masuk_default_terdeteksi_dan_pesannya_bisa_ditindaklanjuti(): void
    {
        /*
         * INILAH KERUSAKAN YANG SESUNGGUHNYA. Begitu satu baris untuk bulan
         * yang belum berpartisi masuk ke DEFAULT, PostgreSQL MENOLAK
         * pembuatan partisi rentang untuk bulan itu — dan perbaikannya
         * menuntut melepas DEFAULT, memindahkan barisnya, lalu memasang
         * ulang, sambil mengunci tabel tersibuk di rumah sakit.
         */
        $jauh = Carbon::now()->addMonths(30)->startOfMonth();

        DB::table('platform.audit_logs')->insert([
            'context' => 'platform',
            'action' => 'uji.partisi',
            'created_at' => $jauh->toDateTimeString(),
        ]);

        $tabel = collect($this->partisi->partitionedTables())->firstWhere('induk', 'platform.audit_logs');

        $this->assertSame(1, $this->partisi->defaultPartitionRows($tabel));
        $this->assertFalse($this->partisi->health()['sehat']);

        // Dan saat perawatan mencoba membuat partisi bulan itu, pesannya
        // harus memberitahu apa yang sebenarnya terjadi — bukan meneruskan
        // "would be violated by some row" apa adanya.
        $this->travelTo($jauh->copy()->subMonth());

        try {
            $this->partisi->ensureRunway(2);
            $this->fail('Pembuatan partisi di atas baris DEFAULT seharusnya gagal.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('telanjur masuk ke partisi DEFAULT', $e->getMessage());
            $this->assertStringContainsString('di luar jam layanan', $e->getMessage());
        }
    }

    #[Test]
    public function partisi_di_luar_range_bulanan_dilaporkan_tidak_terawat(): void
    {
        // Semua tabel berpartisi saat ini berbentuk RANGE per bulan.
        $this->assertSame([], $this->partisi->unmanaged());

        // Kalau suatu hari ada yang membuat partisi LIST, ia harus TERLIHAT
        // tidak terawat — bukan tersembunyi di balik laporan yang menyatakan
        // semuanya beres.
        DB::statement('CREATE TABLE platform.uji_list (kode text, nilai int) PARTITION BY LIST (kode)');

        $takTerawat = $this->partisi->unmanaged();

        $this->assertCount(1, $takTerawat);
        $this->assertStringContainsString('platform.uji_list', $takTerawat[0]);
        $this->assertFalse($this->partisi->health()['sehat']);

        DB::statement('DROP TABLE platform.uji_list');
    }

    #[Test]
    public function perintah_artisan_terdaftar_dan_berjalan(): void
    {
        $this->artisan('partisi:pastikan --kering')
            ->expectsOutputToContain('billing.charge_lines')
            ->assertSuccessful();

        // Pemeriksaan keluar dengan kode 1 saat tidak sehat, supaya
        // pemantauan menangkapnya — yang membacanya mesin, dan mesin cuma
        // mengerti kode keluar.
        $this->artisan('partisi:periksa')->assertSuccessful();

        $this->travelTo(Carbon::now()->addMonths(23));

        $this->artisan('partisi:periksa')->assertFailed();
    }
}
