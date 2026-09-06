<?php

namespace Tests\Feature\Integration;

use App\Modules\Integration\Services\Bpjs\AplicaresClient;
use App\Modules\Integration\Services\Bpjs\AplicaresService;
use App\Modules\Integration\Services\Bpjs\FakeAplicaresClient;
use App\Modules\Integration\Services\IntegrationException;
use App\Modules\Inpatient\Database\Seeders\InpatientSeeder;
use App\Modules\Platform\Database\Seeders\PermissionCatalogSeeder;
use App\Modules\Platform\Database\Seeders\RoleSeeder;
use App\Modules\Platform\Models\User;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Aplicares & iCare BPJS (domain L item B).
 *
 * Yang paling perlu dikunci: ketersediaan yang dikirim ke BPJS SELALU
 * sama dengan yang tampil di layar ketersediaan tempat tidur (dibaca dari
 * kontrak yang sama, bukan salinan), dan kelas yang belum dipetakan TIDAK
 * dikirim dengan kode tebakan melainkan dilaporkan jumlahnya.
 */
class AplicaresTest extends TestCase
{
    use RefreshDatabase;

    private AplicaresService $aplicares;
    private User $petugas;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([
            PermissionCatalogSeeder::class, RoleSeeder::class,
            ReferenceDataSeeder::class, InpatientSeeder::class,
        ]);

        $this->app->bind(AplicaresClient::class, fn () => new FakeAplicaresClient());

        $this->aplicares = app(AplicaresService::class);

        $this->petugas = User::query()->create([
            'username' => 'uji-integrasi-aplicares', 'name' => 'Petugas Integrasi',
            'password' => 'password', 'is_active' => true,
        ]);
    }

    // ------------------------------------------------------------- pemetaan

    #[Test]
    public function kelas_kamar_disiapkan_untuk_dipetakan(): void
    {
        $baru = $this->aplicares->syncRoomClasses();

        $this->assertSame(6, $baru, 'Enam kelas kamar dari seeder ranap');
        $this->assertSame(6, $this->aplicares->mappings()->count());
        $this->assertNull($this->aplicares->mappings()->first()->bpjs_class_code,
            'Kode BPJS dibiarkan kosong, bukan ditebak');
    }

    /** Idempoten: dijalankan lagi tidak menggandakan, dan tidak menimpa yang sudah dipetakan. */
    #[Test]
    public function penyiapan_ulang_tidak_menimpa_pemetaan_yang_ada(): void
    {
        $this->aplicares->syncRoomClasses();
        $this->aplicares->saveMapping('vip', 'VVIP', 'Kelas VVIP', true, $this->petugas->id);

        $this->assertSame(0, $this->aplicares->syncRoomClasses(), 'Tidak ada kelas baru');
        $this->assertSame('VVIP', $this->aplicares->mappings()->firstWhere('room_class', 'vip')->bpjs_class_code);
    }

    /**
     * Dua kelas kamar yang dipetakan ke kode BPJS yang sama akan saling
     * menimpa saat dilaporkan — angka salah satunya hilang tanpa jejak.
     */
    #[Test]
    public function kode_kelas_bpjs_tidak_boleh_dipakai_dua_kelas(): void
    {
        $this->aplicares->syncRoomClasses();
        $this->aplicares->saveMapping('vip', 'K1', 'Kelas 1', true);

        $this->expectException(IntegrationException::class);
        $this->expectExceptionMessage('sudah dipakai kelas kamar lain');

        $this->aplicares->saveMapping('kelas-1', 'K1', 'Kelas 1', true);
    }

    #[Test]
    public function kelas_kamar_yang_tidak_dikenal_ditolak(): void
    {
        $this->aplicares->syncRoomClasses();

        $this->expectException(IntegrationException::class);
        $this->expectExceptionMessage('tidak dikenal');

        $this->aplicares->saveMapping('kelas-hantu', 'KH', 'Hantu', true);
    }

    // ------------------------------------------------------------- pelaporan

    /**
     * Inti item ini: kelas yang belum dipetakan TIDAK dikirim dengan kode
     * tebakan, dan jumlahnya dilaporkan supaya ketahuan.
     */
    #[Test]
    public function kelas_belum_dipetakan_tidak_dikirim_tapi_dihitung(): void
    {
        $this->aplicares->syncRoomClasses();
        $this->aplicares->saveMapping('vip', 'VIP', 'Kelas VIP', true);

        $laporan = $this->aplicares->report($this->petugas->id);

        $this->assertSame(1, $laporan->mapped_classes, 'Hanya yang sudah dipetakan yang dikirim');
        $this->assertSame(5, $laporan->unmapped_classes, 'Yang terlewat dihitung, bukan disembunyikan');
        $this->assertTrue($laporan->success);
    }

    #[Test]
    public function laporan_tanpa_satu_pun_kelas_terpetakan_ditolak_bpjs(): void
    {
        $this->aplicares->syncRoomClasses();

        $laporan = $this->aplicares->report($this->petugas->id);

        $this->assertFalse($laporan->success, 'BPJS menolak laporan kosong');
        $this->assertSame(0, $laporan->mapped_classes);
        $this->assertSame(6, $laporan->unmapped_classes);
        $this->assertStringContainsString('Tidak ada data kamar', $laporan->response_message);
    }

    /**
     * Angka yang dikirim harus sama dengan yang tampil di layar
     * ketersediaan — dibaca dari kontrak yang sama, bukan salinan.
     */
    #[Test]
    public function angka_yang_dikirim_sama_dengan_ketersediaan_di_layar(): void
    {
        $this->aplicares->syncRoomClasses();
        $this->aplicares->saveMapping('kelas-3', 'K3', 'Kelas 3', true);

        $diLayar = $this->aplicares->currentAvailability()->firstWhere('room_class', 'kelas-3');
        $laporan = $this->aplicares->report();

        $dikirim = collect($laporan->payload)->firstWhere('kode_kelas', 'K3');

        $this->assertSame((int) $diLayar->tersedia, $dikirim['tersedia']);
        $this->assertSame((int) $diLayar->terisi, $dikirim['terisi']);
    }

    /** Kelas yang sengaja tidak dilaporkan ikut terhitung sebagai terlewat, bukan diam-diam hilang. */
    #[Test]
    public function kelas_yang_dikecualikan_tidak_dikirim(): void
    {
        $this->aplicares->syncRoomClasses();
        $this->aplicares->saveMapping('vip', 'VIP', 'Kelas VIP', false);
        $this->aplicares->saveMapping('kelas-3', 'K3', 'Kelas 3', true);

        $laporan = $this->aplicares->report();

        $this->assertSame(1, $laporan->mapped_classes);
        $this->assertSame(
            0,
            collect($laporan->payload)->where('kode_kelas', 'VIP')->count(),
            'Kelas yang dikecualikan tidak ikut terkirim'
        );
    }

    #[Test]
    public function tanpa_kamar_terdaftar_pelaporan_ditolak_sejak_awal(): void
    {
        DB::table('inpatient.rooms')->update(['is_active' => false]);

        $this->expectException(IntegrationException::class);
        $this->expectExceptionMessage('Belum ada kamar terdaftar');

        $this->aplicares->report();
    }

    #[Test]
    public function riwayat_pelaporan_tersimpan_termasuk_yang_gagal(): void
    {
        $this->aplicares->syncRoomClasses();
        $this->aplicares->report();                              // gagal, belum dipetakan
        $this->aplicares->saveMapping('vip', 'VIP', 'VIP', true);
        $this->aplicares->report();                              // berhasil

        $riwayat = $this->aplicares->reports();

        $this->assertCount(2, $riwayat, 'Yang gagal tetap tersimpan untuk penelusuran');
        $this->assertTrue($riwayat->first()->success);
        $this->assertFalse($riwayat->last()->success);
    }

    // ---------------------------------------------------------------- iCare

    #[Test]
    public function riwayat_perawatan_peserta_tersimpan_sebagai_salinan(): void
    {
        $hasil = $this->aplicares->memberHistory('0001234567890', null, $this->petugas->id);

        $this->assertTrue($hasil->found);
        $this->assertNotNull($hasil->raw_response, 'Jawaban BPJS disimpan apa adanya');
        $this->assertCount(2, $hasil->raw_response['data']['riwayat']);
    }

    #[Test]
    public function peserta_tidak_ditemukan_tetap_meninggalkan_jejak(): void
    {
        $hasil = $this->aplicares->memberHistory('9001234567890');

        $this->assertFalse($hasil->found);
        $this->assertSame('201', $hasil->response_code);
    }

    #[Test]
    public function icare_gangguan_dicatat_bukan_dilempar(): void
    {
        $hasil = $this->aplicares->memberHistory('8001234567890');

        $this->assertFalse($hasil->found);
        $this->assertStringContainsString('gangguan', $hasil->response_message);
    }

    #[Test]
    public function nomor_kartu_kosong_ditolak(): void
    {
        $this->expectException(IntegrationException::class);
        $this->expectExceptionMessage('Nomor kartu wajib diisi');

        $this->aplicares->memberHistory('   ');
    }
}
