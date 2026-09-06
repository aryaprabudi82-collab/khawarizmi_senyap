<?php

namespace Tests\Feature\Integration;

use App\Modules\Integration\Models\IntegrationCredential;
use App\Modules\Integration\Services\Bpjs\BpjsClient;
use App\Modules\Integration\Services\Bpjs\BpjsVclaimClient;
use App\Modules\Integration\Services\Bpjs\FakeBpjsClient;
use App\Modules\Integration\Services\CredentialStore;
use App\Modules\Integration\Services\IntegrationException;
use App\Modules\Integration\Services\IntegrationHealthService;
use App\Modules\Integration\Services\IntegrationRegistry;
use App\Modules\Platform\Database\Seeders\PermissionCatalogSeeder;
use App\Modules\Platform\Database\Seeders\RoleSeeder;
use App\Modules\Platform\Models\Role;
use App\Modules\Platform\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Rumah integrasi: pengaturan kredensial sistem luar.
 *
 * Tiga hal yang paling perlu dikunci:
 *  - mengisi kredensial LANGSUNG mengaktifkan adapter asli, tanpa
 *    penerapan ulang aplikasi (inti dari "tinggal input");
 *  - nilai rahasia TIDAK PERNAH kembali ke layar, dan kotak kosong berarti
 *    "jangan diubah" — bukan "hapus";
 *  - sistem yang SETENGAH terisi tetap dianggap belum siap.
 */
class IntegrationSettingTest extends TestCase
{
    use RefreshDatabase;

    private CredentialStore $kredensial;
    private User $adminSistem;

    /** @var array<string, string> */
    private array $bpjsLengkap = [
        'base_url' => 'https://apijkn.bpjs-kesehatan.go.id/vclaim-rest',
        'cons_id' => '12345',
        'secret_key' => 'RAHASIA-KONSUMEN-abcd',
        'user_key' => 'USERKEY-wxyz',
        'ppk_code' => '0123R001',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([PermissionCatalogSeeder::class, RoleSeeder::class]);

        $this->kredensial = app(CredentialStore::class);

        $this->adminSistem = User::query()->create([
            'username' => 'uji-admin-integrasi', 'name' => 'Admin Sistem',
            'password' => 'password', 'is_active' => true,
        ]);
        $this->adminSistem->roles()->attach(Role::query()->where('code', 'admin-sistem')->firstOrFail());
    }

    // --------------------------------------------------- inti: tinggal input

    /**
     * INTI PERMINTAAN: mengisi kredensial langsung menukar adapter simulasi
     * jadi adapter asli, tanpa menyentuh .env dan tanpa restart.
     */
    #[Test]
    public function mengisi_kredensial_langsung_mengaktifkan_adapter_asli(): void
    {
        $this->assertInstanceOf(FakeBpjsClient::class, app(BpjsClient::class));

        $this->kredensial->put('bpjs', $this->bpjsLengkap);
        $this->app->forgetInstance(BpjsClient::class);

        $this->assertInstanceOf(BpjsVclaimClient::class, app(BpjsClient::class),
            'Adapter asli aktif begitu kredensial lengkap — tanpa penerapan ulang');
    }

    /**
     * Sistem yang SETENGAH terisi tetap memakai simulasi: ia akan gagal
     * pada panggilan pertama dengan pesan yang tidak masuk akal, dan itu
     * lebih membingungkan daripada sistem yang jelas belum disetel.
     */
    #[Test]
    public function sistem_setengah_terisi_tetap_memakai_simulasi(): void
    {
        $this->kredensial->put('bpjs', [
            'base_url' => 'https://contoh',
            'cons_id' => '12345',
            // secret_key, user_key, ppk_code sengaja dikosongkan
        ]);

        $this->app->forgetInstance(BpjsClient::class);

        $this->assertFalse($this->kredensial->isReady('bpjs'));
        $this->assertInstanceOf(FakeBpjsClient::class, app(BpjsClient::class));
        $this->assertSame(['secret_key', 'user_key', 'ppk_code'], $this->kredensial->missingFields('bpjs'));
    }

    #[Test]
    public function kredensial_dibaca_dari_basis_data_lebih_dulu_daripada_config(): void
    {
        config(['services.bpjs.cons_id' => 'DARI-ENV']);

        $this->assertSame('DARI-ENV', $this->kredensial->get('bpjs', 'cons_id'), 'Cadangan .env terbaca');

        $this->kredensial->put('bpjs', ['cons_id' => 'DARI-LAYAR']);

        $this->assertSame('DARI-LAYAR', $this->kredensial->get('bpjs', 'cons_id'),
            'Yang diisi lewat layar menang atas .env');
    }

    // ------------------------------------------------------- rahasia terjaga

    /** Nilai rahasia disimpan terenkripsi — tidak terbaca dari basis data. */
    #[Test]
    public function nilai_rahasia_tersimpan_terenkripsi(): void
    {
        $this->kredensial->put('bpjs', $this->bpjsLengkap);

        $mentah = DB::table('integration.integration_credentials')
            ->where('field', 'secret_key')
            ->value('value');

        $this->assertNotSame($this->bpjsLengkap['secret_key'], $mentah, 'Ciphertext, bukan teks polos');
        $this->assertStringNotContainsString('RAHASIA-KONSUMEN', $mentah);

        // Tapi tetap terbaca lewat model.
        $this->assertSame($this->bpjsLengkap['secret_key'], $this->kredensial->get('bpjs', 'secret_key'));
    }

    /** Yang tampil ke layar cuma ekornya — cukup memastikan, tidak cukup menyalin. */
    #[Test]
    public function ringkasan_tidak_pernah_membawa_nilai_rahasia_utuh(): void
    {
        $this->kredensial->put('bpjs', $this->bpjsLengkap);

        $bpjs = $this->kredensial->summary()->firstWhere('system', 'bpjs');
        $rahasia = collect($bpjs->fields)->firstWhere('field', 'secret_key');
        $biasa = collect($bpjs->fields)->firstWhere('field', 'ppk_code');

        $this->assertSame('••••abcd', $rahasia->display);
        $this->assertStringNotContainsString('RAHASIA-KONSUMEN', json_encode($bpjs));

        $this->assertSame('0123R001', $biasa->display, 'Yang bukan rahasia tetap terbaca untuk diperiksa');
    }

    /** Kotak rahasia yang dikosongkan berarti "jangan diubah", bukan "hapus". */
    #[Test]
    public function rahasia_kosong_tidak_menimpa_nilai_lama(): void
    {
        $this->kredensial->put('bpjs', $this->bpjsLengkap);

        $this->kredensial->put('bpjs', ['secret_key' => '', 'ppk_code' => '0123R002']);

        $this->assertSame($this->bpjsLengkap['secret_key'], $this->kredensial->get('bpjs', 'secret_key'),
            'Rahasia lama tetap utuh');
        $this->assertSame('0123R002', $this->kredensial->get('bpjs', 'ppk_code'), 'Yang bukan rahasia tetap berubah');
    }

    /** Menghapus harus disengaja, lewat jalur tersendiri. */
    #[Test]
    public function penghapusan_disediakan_terpisah_dan_membuat_sistem_tidak_siap(): void
    {
        $this->kredensial->put('bpjs', $this->bpjsLengkap);
        $this->assertTrue($this->kredensial->isReady('bpjs'));

        $this->kredensial->forget('bpjs', 'secret_key');

        $this->assertFalse($this->kredensial->isReady('bpjs'));
        $this->assertSame(['secret_key'], $this->kredensial->missingFields('bpjs'));
    }

    #[Test]
    public function nilai_tidak_ikut_serialisasi_model(): void
    {
        $this->kredensial->put('bpjs', $this->bpjsLengkap);

        $baris = IntegrationCredential::query()->where('field', 'secret_key')->firstOrFail();

        $this->assertArrayNotHasKey('value', $baris->toArray(),
            'Nilai disembunyikan dari serialisasi supaya tidak bocor lewat log atau respons JSON');
    }

    // ----------------------------------------------------------- registry

    #[Test]
    public function kolom_di_luar_daftar_ditolak(): void
    {
        $this->expectException(IntegrationException::class);
        $this->expectExceptionMessage("Kolom 'pintu_belakang' tidak dikenal");

        $this->kredensial->put('bpjs', ['pintu_belakang' => 'x']);
    }

    #[Test]
    public function sistem_di_luar_daftar_ditolak(): void
    {
        $this->expectException(IntegrationException::class);
        $this->expectExceptionMessage("Sistem integrasi 'sistem-hantu' tidak dikenal");

        $this->kredensial->put('sistem-hantu', ['x' => 'y']);
    }

    #[Test]
    public function seluruh_sistem_terdaftar_muncul_di_ringkasan(): void
    {
        $ringkasan = $this->kredensial->summary();

        $this->assertCount(count(IntegrationRegistry::keys()), $ringkasan);
        $this->assertSame(0, $this->kredensial->readyCount(), 'Belum ada yang diisi');
    }

    // ------------------------------------------------------------ uji koneksi

    /** Sistem yang belum lengkap tidak diuji ke jaringan — pesannya menyebut kolom yang kurang. */
    #[Test]
    public function uji_koneksi_menolak_sistem_yang_belum_lengkap(): void
    {
        $hasil = app(IntegrationHealthService::class)->check('bpjs');

        $this->assertFalse($hasil->success);
        $this->assertStringContainsString('kolom wajib belum terisi', $hasil->message);
        $this->assertStringContainsString('secret_key', $hasil->message);
    }

    /**
     * Galat jaringan DICATAT sebagai kegagalan, bukan meledak di layar
     * orang yang sedang memasang kredensial.
     */
    #[Test]
    public function galat_jaringan_dicatat_bukan_dilempar(): void
    {
        $this->kredensial->put('bpjs', $this->bpjsLengkap + ['base_url' => 'https://alamat-yang-tidak-ada.invalid']);
        $this->app->forgetInstance(BpjsClient::class);

        $hasil = app(IntegrationHealthService::class)->check('bpjs', $this->adminSistem->id);

        $this->assertFalse($hasil->success);
        $this->assertNotNull($hasil->message);
        $this->assertDatabaseCount('integration.integration_health_checks', 1);
    }

    #[Test]
    public function riwayat_uji_terakhir_terbaca_per_sistem(): void
    {
        app(IntegrationHealthService::class)->check('bpjs');

        $terakhir = app(IntegrationHealthService::class)->latest();

        $this->assertNotNull($terakhir['bpjs']);
        $this->assertNull($terakhir['satusehat'], 'Yang belum pernah diuji tetap kosong');
    }

    // ----------------------------------------------------------------- layar

    #[Test]
    public function layar_pengaturan_hanya_untuk_admin_sistem(): void
    {
        $this->actingAs($this->adminSistem)->get(route('integrasi.pengaturan.index'))->assertOk();

        $dokter = User::query()->create([
            'username' => 'uji-dokter-integrasi', 'name' => 'Dokter', 'password' => 'password', 'is_active' => true,
        ]);
        $dokter->roles()->attach(Role::query()->where('code', 'dokter')->firstOrFail());

        $this->actingAs($dokter)->get(route('integrasi.pengaturan.index'))->assertForbidden();
    }

    /**
     * Yang memakai integrasi setiap hari tidak seharusnya bisa mengubah
     * kredensial rumah sakit — gerbangnya sengaja terpisah dari gerbang
     * pemakaian.
     */
    #[Test]
    public function petugas_yang_memakai_integrasi_tidak_bisa_mengubah_kredensialnya(): void
    {
        $petugas = User::query()->create([
            'username' => 'uji-petugas-bpjs', 'name' => 'Petugas Loket', 'password' => 'password', 'is_active' => true,
        ]);
        $petugas->roles()->attach(Role::query()->where('code', 'petugas-daftar')->firstOrFail());

        $this->actingAs($petugas)
            ->post(route('integrasi.pengaturan.simpan', 'bpjs'), ['fields' => ['cons_id' => 'diubah']])
            ->assertForbidden();
    }

    #[Test]
    public function kredensial_bisa_disimpan_lewat_http(): void
    {
        $this->actingAs($this->adminSistem)
            ->post(route('integrasi.pengaturan.simpan', 'bpjs'), ['fields' => $this->bpjsLengkap])
            ->assertRedirect()
            ->assertSessionHas('status');

        $this->kredensial->flush();

        $this->assertTrue($this->kredensial->isReady('bpjs'));
        $this->assertSame($this->adminSistem->id, IntegrationCredential::query()->first()->updated_by);
    }

    #[Test]
    public function penyimpanan_setengah_lengkap_memberi_tahu_kolom_yang_kurang(): void
    {
        $this->actingAs($this->adminSistem)
            ->post(route('integrasi.pengaturan.simpan', 'bpjs'), ['fields' => ['cons_id' => '12345']])
            ->assertRedirect();

        $this->assertStringContainsString('belum siap dipakai', session('status'));
        $this->assertStringContainsString('secret_key', session('status'));
    }

    #[Test]
    public function layar_menyatakan_risiko_kehilangan_app_key(): void
    {
        $this->actingAs($this->adminSistem)
            ->get(route('integrasi.pengaturan.index'))
            ->assertOk()
            ->assertSee('kehilangan APP_KEY sama dengan kehilangan seluruh kredensial', false)
            ->assertSee('Nilai rahasia tidak pernah ditampilkan kembali', false);
    }

    #[Test]
    public function layar_menyatakan_yang_masih_perlu_dilakukan_di_luar_halaman(): void
    {
        $this->actingAs($this->adminSistem)
            ->get(route('integrasi.pengaturan.index'))
            ->assertOk()
            ->assertSee('Verifikasi terhadap sandbox resmi', false)
            ->assertSee('bukan bahwa setiap bentuk permintaan sudah benar', false);
    }
}
