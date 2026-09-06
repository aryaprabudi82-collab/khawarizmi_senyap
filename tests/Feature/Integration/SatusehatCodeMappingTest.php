<?php

namespace Tests\Feature\Integration;

use App\Modules\Integration\Models\SatusehatCodeMapping;
use App\Modules\Integration\Services\IntegrationException;
use App\Modules\Integration\Services\Satusehat\CodeMappingService;
use App\Modules\Platform\Database\Seeders\PermissionCatalogSeeder;
use App\Modules\Platform\Database\Seeders\RoleSeeder;
use App\Modules\Platform\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Pemetaan kode SATUSEHAT (domain L item D).
 *
 * Yang paling perlu dikunci: kode standar dan sistemnya SELALU sepasang,
 * dan yang belum dipetakan TIDAK dikirim dengan kode tebakan. Salah kode
 * di laporan internal bisa diperbaiki; salah kode yang sudah tersebar ke
 * platform nasional dan terbaca fasilitas kesehatan lain jauh lebih sulit
 * ditarik kembali.
 */
class SatusehatCodeMappingTest extends TestCase
{
    use RefreshDatabase;

    private CodeMappingService $pemetaan;
    private User $petugas;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([PermissionCatalogSeeder::class, RoleSeeder::class]);

        $this->pemetaan = app(CodeMappingService::class);

        $this->petugas = User::query()->create([
            'username' => 'uji-integrasi-pemetaan', 'name' => 'Petugas Integrasi',
            'password' => 'password', 'is_active' => true,
        ]);
    }

    // ------------------------------------------------------------- penyiapan

    #[Test]
    public function kode_lokal_disiapkan_tanpa_kode_standar(): void
    {
        $baru = $this->siapkan('tindakan-ralan', ['TL-001', 'TL-002']);

        $this->assertSame(2, $baru);
        $this->assertSame(2, $this->pemetaan->unmappedCount());
        $this->assertNull(
            $this->pemetaan->mappings('tindakan-ralan')->first()->standard_code,
            'Kode standar dibiarkan kosong, bukan ditebak'
        );
    }

    /** Idempoten: dijalankan lagi tidak menggandakan dan tidak menimpa yang sudah dipetakan. */
    #[Test]
    public function penyiapan_ulang_tidak_menimpa_pemetaan_yang_ada(): void
    {
        $this->siapkan('obat', ['OBT-001']);
        $this->pemetaan->map('obat', 'OBT-001', 'kfa', '93000123', 'Parasetamol 500 mg');

        $this->assertSame(0, $this->siapkan('obat', ['OBT-001']), 'Tidak ada yang baru');
        $this->assertSame('93000123', $this->pemetaan->resolve('obat', 'OBT-001')['code']);
    }

    #[Test]
    public function jenis_pemetaan_di_luar_daftar_ditolak(): void
    {
        $this->expectException(IntegrationException::class);
        $this->expectExceptionMessage("Jenis pemetaan 'makanan' tidak dikenal");

        $this->siapkan('makanan', ['X-1']);
    }

    // ---------------------------------------------------- kode & sistem sepasang

    /**
     * INTI ITEM INI: kode standar tanpa sistemnya akan dikirim sebagai
     * sistem yang salah dan ditolak SATUSEHAT dengan pesan yang sulit
     * ditelusuri.
     */
    #[Test]
    public function kode_standar_tanpa_sistemnya_ditolak(): void
    {
        $this->siapkan('lab', ['LAB-001']);

        $this->expectException(IntegrationException::class);
        $this->expectExceptionMessage('harus diisi bersama');

        $this->pemetaan->map('lab', 'LAB-001', null, '2345-7');
    }

    #[Test]
    public function sistem_tanpa_kodenya_juga_ditolak(): void
    {
        $this->siapkan('lab', ['LAB-001']);

        $this->expectException(IntegrationException::class);
        $this->expectExceptionMessage('harus diisi bersama');

        $this->pemetaan->map('lab', 'LAB-001', 'loinc', null);
    }

    #[Test]
    public function sistem_kode_di_luar_daftar_ditolak(): void
    {
        $this->siapkan('lab', ['LAB-001']);

        $this->expectException(IntegrationException::class);
        $this->expectExceptionMessage("Sistem kode 'hl7v2' tidak dikenal");

        $this->pemetaan->map('lab', 'LAB-001', 'hl7v2', '2345-7');
    }

    /** Basis data ikut menjaga, bukan cuma service — kalau ada jalur lain yang menulis. */
    #[Test]
    public function basis_data_menolak_kode_tanpa_sistem(): void
    {
        $this->siapkan('lab', ['LAB-001']);

        $this->expectException(\Illuminate\Database\QueryException::class);

        SatusehatCodeMapping::query()
            ->where('local_code', 'LAB-001')
            ->update(['standard_code' => '2345-7', 'code_system' => null]);
    }

    // ------------------------------------------------------------- pemetaan

    #[Test]
    public function kode_terpetakan_bisa_diambil_berikut_sistemnya(): void
    {
        $this->siapkan('lab', ['LAB-001']);
        $this->pemetaan->map('lab', 'LAB-001', 'loinc', '2345-7', 'Glucose', $this->petugas->id);

        $hasil = $this->pemetaan->resolve('lab', 'LAB-001');

        $this->assertSame('loinc', $hasil['system']);
        $this->assertSame('2345-7', $hasil['code']);
        $this->assertSame('Glucose', $hasil['display']);
    }

    /** Yang belum dipetakan mengembalikan null — pemanggil wajib membacanya sebagai "jangan kirim". */
    #[Test]
    public function kode_belum_dipetakan_mengembalikan_null(): void
    {
        $this->siapkan('lab', ['LAB-001']);

        $this->assertNull($this->pemetaan->resolve('lab', 'LAB-001'));
    }

    #[Test]
    public function kode_nonaktif_tidak_ikut_terpakai(): void
    {
        $this->siapkan('obat', ['OBT-001']);
        $this->pemetaan->map('obat', 'OBT-001', 'kfa', '93000123');

        SatusehatCodeMapping::query()->where('local_code', 'OBT-001')->update(['is_active' => false]);

        $this->assertNull($this->pemetaan->resolve('obat', 'OBT-001'));
    }

    #[Test]
    public function kode_lokal_yang_belum_disiapkan_tidak_bisa_dipetakan(): void
    {
        $this->expectException(IntegrationException::class);
        $this->expectExceptionMessage('tidak dikenal pada pemetaan');

        $this->pemetaan->map('obat', 'OBT-HANTU', 'kfa', '93000123');
    }

    /** Pemetaan bisa dikosongkan lagi kalau ternyata salah. */
    #[Test]
    public function pemetaan_bisa_dikosongkan_kembali(): void
    {
        $this->siapkan('obat', ['OBT-001']);
        $this->pemetaan->map('obat', 'OBT-001', 'kfa', '93000123');

        $this->pemetaan->map('obat', 'OBT-001', null, null);

        $this->assertNull($this->pemetaan->resolve('obat', 'OBT-001'));
        $this->assertSame(1, $this->pemetaan->unmappedCount('obat'));
    }

    // ------------------------------------------------------------- kesiapan

    /**
     * Angka "belum dipetakan" menentukan berapa banyak data klinis yang
     * TIDAK akan terkirim — harus terlihat sebelum ada yang menyimpulkan
     * integrasinya sudah jalan.
     */
    #[Test]
    public function kesiapan_menampilkan_berapa_yang_belum_dipetakan(): void
    {
        $this->siapkan('lab', ['LAB-001', 'LAB-002', 'LAB-003']);
        $this->pemetaan->map('lab', 'LAB-001', 'loinc', '2345-7');

        $kesiapan = $this->pemetaan->readiness()->firstWhere('mapping_type', 'lab');

        $this->assertSame(3, (int) $kesiapan->total);
        $this->assertSame(1, (int) $kesiapan->terpetakan);
        $this->assertSame(2, (int) $kesiapan->belum);
    }

    #[Test]
    public function daftar_bisa_disaring_hanya_yang_belum_dipetakan(): void
    {
        $this->siapkan('lab', ['LAB-001', 'LAB-002']);
        $this->pemetaan->map('lab', 'LAB-001', 'loinc', '2345-7');

        $this->assertCount(2, $this->pemetaan->mappings('lab'));
        $this->assertCount(1, $this->pemetaan->mappings('lab', true));
    }

    /** Tiap sistem kode punya URI resminya, dipasang saat menyusun resource FHIR. */
    #[Test]
    public function sistem_kode_punya_uri_resmi(): void
    {
        $this->siapkan('lab', ['LAB-001']);
        $this->pemetaan->map('lab', 'LAB-001', 'loinc', '2345-7');

        $baris = SatusehatCodeMapping::query()->where('local_code', 'LAB-001')->firstOrFail();

        $this->assertSame('http://loinc.org', $baris->systemUri());
        $this->assertTrue($baris->isMapped());
    }

    // ------------------------------------------------------------------ bantu

    /** @param array<int,string> $codes */
    private function siapkan(string $type, array $codes): int
    {
        return $this->pemetaan->seedFrom(
            $type,
            array_map(fn ($c) => ['code' => $c, 'name' => 'Layanan ' . $c], $codes)
        );
    }
}
