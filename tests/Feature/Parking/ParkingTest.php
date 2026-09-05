<?php

namespace Tests\Feature\Parking;

use App\Modules\Parking\Models\Rate;
use App\Modules\Parking\Models\Session;
use App\Modules\Parking\Services\ParkingException;
use App\Modules\Parking\Services\ParkingMasterService;
use App\Modules\Parking\Services\ParkingSessionService;
use App\Modules\Platform\Database\Seeders\PermissionCatalogSeeder;
use App\Modules\Platform\Database\Seeders\RoleSeeder;
use App\Modules\Platform\Models\Role;
use App\Modules\Platform\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Domain H Khanza ("Parkir", 3 kode). Sisi keluar & perhitungan biaya
 * dibangun di sini meski Khanza tidak punya menu untuk itu — tabel
 * `parkir` Khanza sendiri sudah menyimpan tgl_keluar/jam_keluar/
 * lama_parkir/ttl_biaya, jadi satu baris = satu sesi masuk-keluar.
 */
class ParkingTest extends TestCase
{
    use RefreshDatabase;

    private ParkingSessionService $sesi;
    private ParkingMasterService $master;
    private User $petugas;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([PermissionCatalogSeeder::class, RoleSeeder::class]);

        $this->sesi = app(ParkingSessionService::class);
        $this->master = app(ParkingMasterService::class);

        $this->petugas = User::query()->create([
            'username' => 'uji-parkir', 'name' => 'Petugas Parkir Uji', 'password' => 'password', 'is_active' => true,
        ]);
        $this->petugas->roles()->attach(Role::query()->where('code', 'petugas-parkir')->firstOrFail());
    }

    #[Test]
    public function tarif_per_jam_dibulatkan_ke_atas(): void
    {
        $tarif = $this->tarifJam(2000);

        $this->assertSame(2000, $tarif->feeFor(1));
        $this->assertSame(2000, $tarif->feeFor(60));
        $this->assertSame(4000, $tarif->feeFor(61));
        $this->assertSame(6000, $tarif->feeFor(121));
    }

    #[Test]
    public function tarif_harian_dihitung_per_24_jam_dibulatkan_ke_atas(): void
    {
        $tarif = $this->master->createRate([
            'code' => 'HRN', 'name' => 'Mobil Inap', 'fee' => 15000, 'basis' => Rate::BASIS_HARIAN, 'free_minutes' => 0,
        ]);

        $this->assertSame(15000, $tarif->feeFor(60));
        $this->assertSame(15000, $tarif->feeFor(24 * 60));
        $this->assertSame(30000, $tarif->feeFor(24 * 60 + 1));
    }

    #[Test]
    public function menit_bebas_biaya_tidak_ditagih_sama_sekali(): void
    {
        $tarif = $this->tarifJam(2000, freeMinutes: 15);

        $this->assertSame(0, $tarif->feeFor(15), 'Antar-jemput dalam toleransi harus gratis');
        $this->assertSame(2000, $tarif->feeFor(16));
        $this->assertSame(2000, $tarif->feeFor(75), '75 menit dikurangi 15 menit bebas = 60 menit = 1 jam');
        $this->assertSame(4000, $tarif->feeFor(76));
    }

    #[Test]
    public function kendaraan_masuk_lalu_keluar_menghitung_durasi_dan_biaya(): void
    {
        $tarif = $this->tarifJam(3000);

        $this->travelTo(now()->setTime(8, 0));
        $sesi = $this->sesi->checkIn($tarif, 'b 1234 xyz', $this->petugas);

        $this->assertTrue($sesi->isOpen());
        $this->assertSame('B 1234 XYZ', $sesi->vehicle_number, 'Nomor kendaraan dinormalkan huruf besar');

        $this->travelTo(now()->setTime(10, 30));
        $sesi = $this->sesi->checkOut($sesi, $this->petugas);

        $this->assertFalse($sesi->isOpen());
        $this->assertSame(150, $sesi->duration_minutes);
        $this->assertSame(9000, $sesi->total_fee, '150 menit dibulatkan jadi 3 jam x Rp 3.000');
        $this->assertSame($this->petugas->id, $sesi->exited_by);
    }

    /**
     * Regresi zona waktu. Tes lain memakai objek Session yang sama seperti
     * saat checkIn, jadi entered_at-nya masih Carbon di memori dan tidak
     * pernah bolak-balik ke database — persis titik buta yang membuat bug
     * ini lolos: kalau session timezone PostgreSQL beda dari app.timezone,
     * datetime yang ditulis Laravel (string polos tanpa offset) ditafsirkan
     * ulang oleh PG dan durasinya meleset sebesar selisih zona. Di sini
     * sesinya sengaja diambil ulang dari database dulu.
     */
    #[Test]
    public function durasi_tetap_benar_saat_sesi_dibaca_ulang_dari_database(): void
    {
        $tarif = $this->tarifJam(2000);

        $this->travelTo(now()->setTime(8, 0));
        $dibuat = $this->sesi->checkIn($tarif, 'B 10 TZ', $this->petugas);

        $this->travelTo(now()->setTime(10, 20));
        $dariDatabase = Session::query()->findOrFail($dibuat->id);
        $selesai = $this->sesi->checkOut($dariDatabase, $this->petugas);

        $this->assertSame(140, $selesai->duration_minutes, 'Durasi tidak boleh bergeser gara-gara zona waktu database');
        $this->assertSame(6000, $selesai->total_fee, '140 menit dibulatkan jadi 3 jam x Rp 2.000');
    }

    #[Test]
    public function biaya_dibekukan_saat_keluar_tidak_ikut_berubah_kalau_tarif_naik(): void
    {
        $tarif = $this->tarifJam(2000);

        $this->travelTo(now()->setTime(9, 0));
        $sesi = $this->sesi->checkIn($tarif, 'B 1 A', $this->petugas);
        $this->travelTo(now()->setTime(10, 0));
        $sesi = $this->sesi->checkOut($sesi, $this->petugas);

        $this->assertSame(2000, $sesi->total_fee);

        $this->master->updateRate($tarif, ['fee' => 5000]);

        $this->assertSame(2000, $sesi->refresh()->total_fee, 'Sesi lama harus tetap menunjukkan biaya yang sungguh ditagihkan');
    }

    #[Test]
    public function kendaraan_yang_masih_di_dalam_tidak_bisa_masuk_lagi(): void
    {
        $tarif = $this->tarifJam(2000);
        $this->sesi->checkIn($tarif, 'B 9 Z', $this->petugas);

        $this->expectException(ParkingException::class);
        $this->expectExceptionMessage('masih di dalam');

        $this->sesi->checkIn($tarif, 'B 9 Z', $this->petugas);
    }

    #[Test]
    public function kartu_yang_masih_menempel_kendaraan_lain_tidak_bisa_dipakai(): void
    {
        $tarif = $this->tarifJam(2000);
        $kartu = $this->master->registerCard('BC-001', 'A01');

        $this->sesi->checkIn($tarif, 'B 1 A', $this->petugas, $kartu);

        $this->expectException(ParkingException::class);
        $this->expectExceptionMessage('masih dipakai');

        $this->sesi->checkIn($tarif, 'B 2 B', $this->petugas, $kartu);
    }

    #[Test]
    public function kartu_yang_sama_bisa_dipakai_lagi_setelah_kendaraan_sebelumnya_keluar(): void
    {
        $tarif = $this->tarifJam(2000);
        $kartu = $this->master->registerCard('BC-002', 'A02');

        $pertama = $this->sesi->checkIn($tarif, 'B 1 A', $this->petugas, $kartu);
        $this->sesi->checkOut($pertama, $this->petugas);

        $kedua = $this->sesi->checkIn($tarif, 'B 2 B', $this->petugas, $kartu);

        $this->assertSame($kartu->id, $kedua->barcode_card_id);
        $this->assertSame(2, Session::query()->count());
    }

    #[Test]
    public function kartu_nonaktif_dan_jenis_nonaktif_ditolak(): void
    {
        $tarif = $this->tarifJam(2000);
        $kartu = $this->master->registerCard('BC-003', 'A03');
        $this->master->deactivateCard($kartu);

        try {
            $this->sesi->checkIn($tarif, 'B 3 C', $this->petugas, $kartu->refresh());
            $this->fail('Kartu nonaktif seharusnya ditolak');
        } catch (ParkingException $e) {
            $this->assertStringContainsString('nonaktif', $e->getMessage());
        }

        $this->master->updateRate($tarif, ['is_active' => false]);

        $this->expectException(ParkingException::class);
        $this->sesi->checkIn($tarif->refresh(), 'B 4 D', $this->petugas);
    }

    #[Test]
    public function kartu_yang_masih_dipakai_tidak_bisa_dinonaktifkan(): void
    {
        $tarif = $this->tarifJam(2000);
        $kartu = $this->master->registerCard('BC-004', 'A04');
        $this->sesi->checkIn($tarif, 'B 5 E', $this->petugas, $kartu);

        $this->expectException(ParkingException::class);

        $this->master->deactivateCard($kartu);
    }

    #[Test]
    public function sesi_yang_sudah_ditutup_tidak_bisa_ditutup_dua_kali(): void
    {
        $tarif = $this->tarifJam(2000);
        $sesi = $this->sesi->checkIn($tarif, 'B 6 F', $this->petugas);
        $this->sesi->checkOut($sesi, $this->petugas);

        $this->expectException(ParkingException::class);
        $this->expectExceptionMessage('sudah ditutup');

        $this->sesi->checkOut($sesi->refresh(), $this->petugas);
    }

    #[Test]
    public function sesi_terbuka_bisa_dicari_lewat_plat_nomor_kartu_maupun_barcode(): void
    {
        $tarif = $this->tarifJam(2000);
        $kartu = $this->master->registerCard('BC-005', 'A05');
        $sesi = $this->sesi->checkIn($tarif, 'B 7 G', $this->petugas, $kartu);

        $this->assertSame($sesi->id, $this->sesi->cariSesiTerbuka('b 7 g')?->id);
        $this->assertSame($sesi->id, $this->sesi->cariSesiTerbuka('A05')?->id);
        $this->assertSame($sesi->id, $this->sesi->cariSesiTerbuka('BC-005')?->id);

        $this->sesi->checkOut($sesi, $this->petugas);

        $this->assertNull($this->sesi->cariSesiTerbuka('B 7 G'), 'Sesi tertutup tidak boleh ikut terjaring');
    }

    #[Test]
    public function alur_masuk_dan_keluar_bisa_lewat_http(): void
    {
        $tarif = $this->tarifJam(2000);

        $this->actingAs($this->petugas)->post(route('parking.sesi.masuk'), [
            'rate_id' => $tarif->id, 'vehicle_number' => 'B 8 H',
        ])->assertRedirect()->assertSessionHas('sukses');

        $sesi = Session::query()->firstOrFail();

        $this->actingAs($this->petugas)->post(route('parking.sesi.keluar', $sesi))
            ->assertRedirect()->assertSessionHas('sukses');

        $this->assertNotNull($sesi->refresh()->exited_at);
    }

    #[Test]
    public function galat_masuk_jadi_pesan_di_layar_bukan_error_500(): void
    {
        $tarif = $this->tarifJam(2000);
        $this->sesi->checkIn($tarif, 'B 9 I', $this->petugas);

        $this->actingAs($this->petugas)->post(route('parking.sesi.masuk'), [
            'rate_id' => $tarif->id, 'vehicle_number' => 'B 9 I',
        ])->assertRedirect()->assertSessionHas('galat');

        $this->assertSame(1, Session::query()->count());
    }

    #[Test]
    public function jenis_dan_kartu_bisa_ditambah_lewat_http_di_layar_master_yang_sama(): void
    {
        $this->actingAs($this->petugas)->post(route('parking.master.tarif.simpan'), [
            'code' => 'MTR', 'name' => 'Motor', 'fee' => 2000, 'basis' => 'jam', 'free_minutes' => 15,
        ])->assertRedirect()->assertSessionHas('sukses');

        // parkir_barcode dilebur ke gerbang parkir_jenis, bukan gerbang sendiri.
        $this->actingAs($this->petugas)->post(route('parking.master.kartu.simpan'), [
            'barcode' => 'BC-100', 'card_number' => 'A99',
        ])->assertRedirect()->assertSessionHas('sukses');

        $this->assertDatabaseHas('parking.rates', ['code' => 'MTR', 'free_minutes' => 15]);
        $this->assertDatabaseHas('parking.barcode_cards', ['card_number' => 'A99']);
    }

    #[Test]
    public function layar_parkir_hanya_untuk_petugas_parkir(): void
    {
        $this->actingAs($this->petugas)->get(route('parking.master.index'))->assertOk();
        $this->actingAs($this->petugas)->get(route('parking.sesi.index'))->assertOk();

        $dokter = User::query()->create([
            'username' => 'uji-dokter-parkir', 'name' => 'Dokter Uji', 'password' => 'password', 'is_active' => true,
        ]);
        $dokter->roles()->attach(Role::query()->where('code', 'dokter')->firstOrFail());

        $this->actingAs($dokter)->get(route('parking.sesi.index'))->assertForbidden();
        $this->actingAs($dokter)->get(route('parking.master.index'))->assertForbidden();
    }

    // ------------------------------------------------------------------ bantu

    private function tarifJam(int $fee, int $freeMinutes = 0): Rate
    {
        return $this->master->createRate([
            'code' => 'J' . str_pad((string) Rate::query()->count(), 2, '0', STR_PAD_LEFT),
            'name' => 'Motor Uji',
            'fee' => $fee,
            'basis' => Rate::BASIS_JAM,
            'free_minutes' => $freeMinutes,
        ]);
    }
}
