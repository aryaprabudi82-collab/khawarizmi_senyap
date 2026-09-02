<?php

namespace Tests\Feature\Integration;

use App\Modules\Catalog\Models\Payer;
use App\Modules\Encounter\Models\Registration;
use App\Modules\Encounter\Services\RegistrationService;
use App\Modules\Identity\Services\PatientRegistry;
use App\Modules\Integration\Models\BpjsSep;
use App\Modules\Integration\Services\Bpjs\EligibilityService;
use App\Modules\Integration\Services\Bpjs\SepService;
use App\Modules\Integration\Services\IdentityMappingService;
use App\Modules\Organization\Models\Unit;
use App\Modules\Platform\Database\Seeders\PermissionCatalogSeeder;
use App\Modules\Platform\Database\Seeders\RoleSeeder;
use App\Modules\Platform\Models\Role;
use App\Modules\Platform\Models\User;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

class BpjsTest extends TestCase
{
    use RefreshDatabase;

    private SepService $sep;
    private EligibilityService $eligibility;
    private IdentityMappingService $mappings;
    private User $petugas;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([PermissionCatalogSeeder::class, RoleSeeder::class, ReferenceDataSeeder::class]);

        $this->sep = app(SepService::class);
        $this->eligibility = app(EligibilityService::class);
        $this->mappings = app(IdentityMappingService::class);

        $this->petugas = User::query()->create([
            'username' => 'uji-integrasi', 'name' => 'Petugas Integrasi Uji', 'password' => 'password', 'is_active' => true,
        ]);
        $this->petugas->roles()->attach(Role::query()->where('code', 'petugas-integrasi')->firstOrFail());
    }

    #[Test]
    public function kartu_yang_diawali_9_tidak_eligible_lewat_adapter_palsu(): void
    {
        $hasil = $this->eligibility->check('9000000000001', now()->toDateString());

        $this->assertFalse($hasil->is_eligible);
    }

    #[Test]
    public function kartu_biasa_eligible_lewat_adapter_palsu(): void
    {
        $hasil = $this->eligibility->check('0001234567890', now()->toDateString());

        $this->assertTrue($hasil->is_eligible);
        $this->assertNotNull($hasil->participant_name);
    }

    #[Test]
    public function sep_ditolak_untuk_kunjungan_yang_bukan_penjamin_bpjs(): void
    {
        $registrasi = $this->daftarkan('UMUM');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('bukan penjamin BPJS');

        $this->sep->create($registrasi->id, '0001234567890', null, $this->petugas->id);
    }

    #[Test]
    public function sep_ditolak_kalau_poli_belum_dipetakan_ke_kode_bpjs(): void
    {
        $registrasi = $this->daftarkan('BPJS');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('belum dipetakan');

        $this->sep->create($registrasi->id, '0001234567890', 'RJ0001', $this->petugas->id);
    }

    #[Test]
    public function sep_berhasil_terbit_setelah_poli_dipetakan(): void
    {
        $this->petakanPoliUmum();
        $registrasi = $this->daftarkan('BPJS');

        $terbit = $this->sep->create($registrasi->id, '0001234567890', 'RJ0001', $this->petugas->id);

        $this->assertSame(BpjsSep::STATUS_TERBIT, $terbit->status);
        $this->assertNotNull($terbit->sep_number);
        $this->assertSame(BpjsSep::JENIS_RALAN, $terbit->jenis_pelayanan);
    }

    #[Test]
    public function sep_rawat_jalan_wajib_no_rujukan(): void
    {
        $this->petakanPoliUmum();
        $registrasi = $this->daftarkan('BPJS');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('No. Rujukan wajib diisi');

        $this->sep->create($registrasi->id, '0001234567890', null, $this->petugas->id);
    }

    #[Test]
    public function kunjungan_tidak_boleh_punya_dua_sep_aktif_sekaligus(): void
    {
        $this->petakanPoliUmum();
        $registrasi = $this->daftarkan('BPJS');
        $this->sep->create($registrasi->id, '0001234567890', 'RJ0001', $this->petugas->id);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('sudah punya SEP');

        $this->sep->create($registrasi->id, '0001234567890', 'RJ0002', $this->petugas->id);
    }

    #[Test]
    public function sep_yang_dibatalkan_membuka_jalan_untuk_sep_baru(): void
    {
        $this->petakanPoliUmum();
        $registrasi = $this->daftarkan('BPJS');
        $pertama = $this->sep->create($registrasi->id, '0001234567890', 'RJ0001', $this->petugas->id);

        $dibatalkan = $this->sep->cancel($pertama, 'Salah input no. rujukan.');
        $this->assertSame(BpjsSep::STATUS_BATAL, $dibatalkan->status);

        $kedua = $this->sep->create($registrasi->id, '0001234567890', 'RJ0002', $this->petugas->id);
        $this->assertSame(BpjsSep::STATUS_TERBIT, $kedua->status);
        $this->assertNotSame($pertama->id, $kedua->id);
    }

    #[Test]
    public function layar_bpjs_hanya_untuk_petugas_integrasi(): void
    {
        $this->actingAs($this->petugas)->get(route('integrasi.bpjs.index'))->assertOk();

        $dokter = User::query()->create([
            'username' => 'uji-dokter-bpjs', 'name' => 'Dokter Uji', 'password' => 'password', 'is_active' => true,
        ]);
        $dokter->roles()->attach(Role::query()->where('code', 'dokter')->firstOrFail());

        $this->actingAs($dokter)->get(route('integrasi.bpjs.index'))->assertForbidden();
    }

    // ------------------------------------------------------------------ bantu

    private function petakanPoliUmum(): void
    {
        $unit = Unit::query()->where('code', 'POL-UMUM')->firstOrFail();
        $this->mappings->setManually('bpjs', 'poli', 'organization', $unit->id, 'RJ0001', $this->petugas->id);
    }

    private function daftarkan(string $kodePenjamin): Registration
    {
        static $urut = 0;
        $urut++;

        $pasien = app(PatientRegistry::class)->register([
            'name' => 'Pasien BPJS Uji ' . $urut, 'sex' => 'L', 'birth_date' => '1990-01-01',
        ]);

        return app(RegistrationService::class)->register(
            patientId: $pasien->id,
            unitId: Unit::query()->where('code', 'POL-UMUM')->value('id'),
            payerId: Payer::query()->where('code', $kodePenjamin)->value('id'),
        );
    }
}
