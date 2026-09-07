<?php

namespace Tests\Feature\Integration;

use App\Modules\Integration\Models\PayerCodeMapping;
use App\Modules\Integration\Models\PayerReference;
use App\Modules\Integration\Services\IntegrationException;
use App\Modules\Integration\Services\PayerReferenceService;
use App\Modules\Platform\Database\Seeders\PermissionCatalogSeeder;
use App\Modules\Platform\Database\Seeders\RoleSeeder;
use App\Modules\Platform\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Referensi & pemetaan kode penjamin (domain L item E).
 *
 * Yang paling perlu dikunci: penyegaran MENGGANTI daftar, bukan menambah —
 * kode yang sudah dihapus penjamin harus ikut hilang, kalau tidak poli yang
 * sudah ditutup tetap bisa dipilih dan klaimnya ditolak tanpa sebab yang
 * jelas. Dan satu kode penjamin tidak boleh dipakai dua kode lokal.
 */
class PayerReferenceTest extends TestCase
{
    use RefreshDatabase;

    private PayerReferenceService $referensi;
    private User $petugas;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([PermissionCatalogSeeder::class, RoleSeeder::class]);

        $this->referensi = app(PayerReferenceService::class);

        $this->petugas = User::query()->create([
            'username' => 'uji-integrasi-referensi', 'name' => 'Petugas Integrasi',
            'password' => 'password', 'is_active' => true,
        ]);
    }

    // ------------------------------------------------------------- referensi

    #[Test]
    public function daftar_referensi_tersimpan_dan_bisa_dicari(): void
    {
        $this->referensi->refresh('bpjs', 'poli', [
            ['code' => 'INT', 'name' => 'Penyakit Dalam'],
            ['code' => 'ANA', 'name' => 'Anak'],
        ]);

        $this->assertCount(2, $this->referensi->references('bpjs', 'poli'));
        $this->assertCount(1, $this->referensi->references('bpjs', 'poli', 'dalam'));
        $this->assertCount(1, $this->referensi->references('bpjs', 'poli', 'ANA'));
    }

    /**
     * INTI ITEM INI: penyegaran mengganti seluruhnya. Kode yang dihapus
     * penjamin harus ikut hilang — kalau cuma ditambahkan, poli yang sudah
     * ditutup tetap bisa dipilih dan klaimnya ditolak tanpa sebab jelas.
     */
    #[Test]
    public function penyegaran_mengganti_daftar_bukan_menambahkan(): void
    {
        $this->referensi->refresh('bpjs', 'poli', [
            ['code' => 'INT', 'name' => 'Penyakit Dalam'],
            ['code' => 'LAMA', 'name' => 'Poli Yang Ditutup'],
        ]);

        // BPJS menutup satu poli; daftar berikutnya tidak lagi memuatnya.
        $this->referensi->refresh('bpjs', 'poli', [
            ['code' => 'INT', 'name' => 'Penyakit Dalam'],
        ]);

        $daftar = $this->referensi->references('bpjs', 'poli');

        $this->assertCount(1, $daftar);
        $this->assertSame('INT', $daftar->first()->code);
    }

    /** Penyegaran hanya mengganti jenis yang disegarkan, bukan seluruh penjamin. */
    #[Test]
    public function penyegaran_satu_jenis_tidak_menghapus_jenis_lain(): void
    {
        $this->referensi->refresh('bpjs', 'poli', [['code' => 'INT', 'name' => 'Penyakit Dalam']]);
        $this->referensi->refresh('bpjs', 'dokter', [['code' => 'D-1', 'name' => 'dr. Uji']]);

        $this->referensi->refresh('bpjs', 'poli', [['code' => 'ANA', 'name' => 'Anak']]);

        $this->assertCount(1, $this->referensi->references('bpjs', 'dokter'), 'Daftar dokter tidak ikut terhapus');
    }

    /**
     * Daftar kosong dari API yang sedang bermasalah TIDAK boleh menghapus
     * salinan lama — itu akan melumpuhkan pendaftaran sampai API pulih.
     */
    #[Test]
    public function penyegaran_dengan_daftar_kosong_ditolak(): void
    {
        $this->referensi->refresh('bpjs', 'poli', [['code' => 'INT', 'name' => 'Penyakit Dalam']]);

        try {
            $this->referensi->refresh('bpjs', 'poli', []);
            $this->fail('Seharusnya menolak daftar kosong');
        } catch (IntegrationException $e) {
            $this->assertStringContainsString('kosong', $e->getMessage());
        }

        $this->assertCount(1, $this->referensi->references('bpjs', 'poli'), 'Salinan lama tetap utuh');
    }

    #[Test]
    public function referensi_berjenjang_disaring_menurut_induknya(): void
    {
        $this->referensi->refresh('bpjs', 'kabupaten', [
            ['code' => '3276', 'name' => 'Kota Depok', 'parent_code' => '32'],
            ['code' => '3171', 'name' => 'Jakarta Pusat', 'parent_code' => '31'],
        ]);

        $jabar = $this->referensi->references('bpjs', 'kabupaten', null, '32');

        $this->assertCount(1, $jabar);
        $this->assertSame('Kota Depok', $jabar->first()->name);
    }

    /** Referensi basi harus terlihat basi, bukan tampak sahih selamanya. */
    #[Test]
    public function umur_salinan_referensi_dilaporkan(): void
    {
        $this->referensi->refresh('bpjs', 'poli', [['code' => 'INT', 'name' => 'Penyakit Dalam']]);

        DB::table('integration.payer_references')->update(['fetched_at' => now()->subDays(45)]);

        $umur = $this->referensi->freshness()->firstWhere('reference_type', 'poli');

        $this->assertSame(45, (int) $umur->umur_hari);
        $this->assertSame(45, PayerReference::query()->first()->ageInDays());
    }

    #[Test]
    public function penjamin_di_luar_daftar_ditolak(): void
    {
        $this->expectException(IntegrationException::class);
        $this->expectExceptionMessage("Sistem referensi 'mandiri-inhealth-lama' tidak dikenal");

        $this->referensi->refresh('mandiri-inhealth-lama', 'poli', [['code' => 'X', 'name' => 'X']]);
    }

    // -------------------------------------------------------------- pemetaan

    #[Test]
    public function kode_lokal_disiapkan_tanpa_kode_penjamin(): void
    {
        $baru = $this->referensi->seedMappings('bpjs', 'poli', [
            ['code' => 'POL-UMUM', 'name' => 'Poliklinik Umum'],
            ['code' => 'POL-GIGI', 'name' => 'Poliklinik Gigi'],
        ]);

        $this->assertSame(2, $baru);
        $this->assertNull($this->referensi->resolve('bpjs', 'poli', 'POL-UMUM'));
        $this->assertCount(2, $this->referensi->mappings('bpjs', 'poli', true));
    }

    #[Test]
    public function kode_penjamin_bisa_dipetakan_dan_diambil(): void
    {
        $this->referensi->seedMappings('bpjs', 'poli', [['code' => 'POL-UMUM', 'name' => 'Poliklinik Umum']]);
        $this->referensi->map('bpjs', 'poli', 'POL-UMUM', 'INT', 'Penyakit Dalam', $this->petugas->id);

        $this->assertSame('INT', $this->referensi->resolve('bpjs', 'poli', 'POL-UMUM'));
    }

    /**
     * Satu kode penjamin dipakai dua kode lokal berarti klaim untuk poli A
     * terkirim sebagai poli B — dan salahnya baru terlihat saat ditolak.
     */
    #[Test]
    public function satu_kode_penjamin_tidak_boleh_dipakai_dua_kode_lokal(): void
    {
        $this->referensi->seedMappings('bpjs', 'poli', [
            ['code' => 'POL-UMUM', 'name' => 'Umum'],
            ['code' => 'POL-GIGI', 'name' => 'Gigi'],
        ]);

        $this->referensi->map('bpjs', 'poli', 'POL-UMUM', 'INT');

        $this->expectException(IntegrationException::class);
        $this->expectExceptionMessage('sudah dipakai kode lokal lain');

        $this->referensi->map('bpjs', 'poli', 'POL-GIGI', 'INT');
    }

    /** Kode yang sama boleh dipakai pada JENIS pemetaan berbeda. */
    #[Test]
    public function kode_yang_sama_boleh_dipakai_pada_jenis_berbeda(): void
    {
        $this->referensi->seedMappings('bpjs', 'poli', [['code' => 'X-1', 'name' => 'Poli']]);
        $this->referensi->seedMappings('bpjs', 'dokter', [['code' => 'X-1', 'name' => 'Dokter']]);

        $this->referensi->map('bpjs', 'poli', 'X-1', 'INT');
        $this->referensi->map('bpjs', 'dokter', 'X-1', 'INT');

        $this->assertSame('INT', $this->referensi->resolve('bpjs', 'poli', 'X-1'));
        $this->assertSame('INT', $this->referensi->resolve('bpjs', 'dokter', 'X-1'));
    }

    /** Penjamin berbeda punya pemetaan sendiri-sendiri. */
    #[Test]
    public function pemetaan_tiap_penjamin_terpisah(): void
    {
        $this->referensi->seedMappings('bpjs', 'poli', [['code' => 'POL-UMUM', 'name' => 'Umum']]);
        $this->referensi->seedMappings('inhealth', 'poli', [['code' => 'POL-UMUM', 'name' => 'Umum']]);

        $this->referensi->map('bpjs', 'poli', 'POL-UMUM', 'INT');
        $this->referensi->map('inhealth', 'poli', 'POL-UMUM', 'PD');

        $this->assertSame('INT', $this->referensi->resolve('bpjs', 'poli', 'POL-UMUM'));
        $this->assertSame('PD', $this->referensi->resolve('inhealth', 'poli', 'POL-UMUM'));
    }

    #[Test]
    public function pemetaan_bisa_dikosongkan_kembali(): void
    {
        $this->referensi->seedMappings('bpjs', 'poli', [['code' => 'POL-UMUM', 'name' => 'Umum']]);
        $this->referensi->map('bpjs', 'poli', 'POL-UMUM', 'INT');

        $this->referensi->map('bpjs', 'poli', 'POL-UMUM', null);

        $this->assertNull($this->referensi->resolve('bpjs', 'poli', 'POL-UMUM'));
    }

    #[Test]
    public function pemetaan_nonaktif_tidak_terpakai(): void
    {
        $this->referensi->seedMappings('bpjs', 'poli', [['code' => 'POL-UMUM', 'name' => 'Umum']]);
        $this->referensi->map('bpjs', 'poli', 'POL-UMUM', 'INT');

        PayerCodeMapping::query()->where('local_code', 'POL-UMUM')->update(['is_active' => false]);

        $this->assertNull($this->referensi->resolve('bpjs', 'poli', 'POL-UMUM'));
    }

    /** Berapa yang belum dipetakan menentukan berapa klaim yang akan tertahan. */
    #[Test]
    public function kesiapan_menampilkan_berapa_yang_belum_dipetakan(): void
    {
        $this->referensi->seedMappings('bpjs', 'poli', [
            ['code' => 'A', 'name' => 'A'], ['code' => 'B', 'name' => 'B'], ['code' => 'C', 'name' => 'C'],
        ]);
        $this->referensi->map('bpjs', 'poli', 'A', 'INT');

        $kesiapan = $this->referensi->readiness()->firstWhere('mapping_type', 'poli');

        $this->assertSame(3, (int) $kesiapan->total);
        $this->assertSame(1, (int) $kesiapan->terpetakan);
        $this->assertSame(2, (int) $kesiapan->belum);
    }
}
