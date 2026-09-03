<?php

namespace Tests\Feature\Blood;

use App\Modules\Blood\Models\BloodUnit;
use App\Modules\Blood\Services\BloodException;
use App\Modules\Blood\Services\BloodUnitService;
use App\Modules\Blood\Services\DonorService;
use App\Modules\Blood\Services\TransfusionService;
use App\Modules\Platform\Database\Seeders\PermissionCatalogSeeder;
use App\Modules\Platform\Database\Seeders\RoleSeeder;
use App\Modules\Platform\Models\Role;
use App\Modules\Platform\Models\User;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class BloodTest extends TestCase
{
    use RefreshDatabase;

    private DonorService $donors;
    private BloodUnitService $units;
    private TransfusionService $transfusion;
    private User $petugas;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([PermissionCatalogSeeder::class, RoleSeeder::class, ReferenceDataSeeder::class]);

        $this->donors = app(DonorService::class);
        $this->units = app(BloodUnitService::class);
        $this->transfusion = app(TransfusionService::class);

        $this->petugas = User::query()->create([
            'username' => 'uji-utd', 'name' => 'Petugas UTD Uji', 'password' => 'password', 'is_active' => true,
        ]);
        $this->petugas->roles()->attach(Role::query()->where('code', 'petugas-utd')->firstOrFail());
    }

    #[Test]
    public function pendonor_terdaftar_mendapat_nomor_berformat_dnr_tahun_urut(): void
    {
        $pendonor = $this->daftarkanPendonor();

        $this->assertMatchesRegularExpression('/^DNR-\d{4}-\d{5}$/', $pendonor->donor_number);
    }

    #[Test]
    public function unit_darah_baru_berstatus_karantina(): void
    {
        $unit = $this->ambilUnit();

        $this->assertSame(BloodUnit::STATUS_KARANTINA, $unit->status);
        $this->assertMatchesRegularExpression('/^UTD-\d{4}-\d{5}$/', $unit->unit_number);
    }

    #[Test]
    public function unit_tidak_bisa_dikeluarkan_langsung_dari_karantina(): void
    {
        $unit = $this->ambilUnit();

        $this->expectException(BloodException::class);

        $this->transfusion->issue($unit, 'Pasien Uji', $this->petugas);
    }

    #[Test]
    public function unit_dirilis_lalu_bisa_dikeluarkan_untuk_transfusi(): void
    {
        $unit = $this->units->release($this->ambilUnit(), $this->petugas);
        $this->assertSame(BloodUnit::STATUS_TERSEDIA, $unit->status);

        $penyerahan = $this->transfusion->issue($unit, 'Pasien Uji', $this->petugas, indication: 'Anemia berat pascaoperasi');

        $this->assertMatchesRegularExpression('/^TRF-\d{4}-\d{5}$/', $penyerahan->issue_number);
        $this->assertSame(BloodUnit::STATUS_DIKELUARKAN, $unit->refresh()->status);
    }

    #[Test]
    public function unit_yang_sudah_dikeluarkan_tidak_bisa_dikeluarkan_lagi(): void
    {
        $unit = $this->units->release($this->ambilUnit(), $this->petugas);
        $this->transfusion->issue($unit, 'Pasien Uji', $this->petugas);

        $this->expectException(BloodException::class);

        $this->transfusion->issue($unit->refresh(), 'Pasien Lain', $this->petugas);
    }

    #[Test]
    public function unit_kedaluwarsa_tidak_bisa_ditransfusikan_meski_berstatus_tersedia(): void
    {
        $unit = $this->units->release($this->ambilUnit(['expiry_date' => now()->subDay()->toDateString()]), $this->petugas);

        $this->expectException(BloodException::class);
        $this->expectExceptionMessage('kedaluwarsa');

        $this->transfusion->issue($unit, 'Pasien Uji', $this->petugas);
    }

    #[Test]
    public function unit_yang_ditahan_bisa_dirilis_kembali_lalu_dikeluarkan(): void
    {
        $unit = $this->units->release($this->ambilUnit(), $this->petugas);
        $unit = $this->units->hold($unit, $this->petugas, 'Hasil skrining ulang meragukan');
        $this->assertSame(BloodUnit::STATUS_DITAHAN, $unit->status);

        $unit = $this->units->release($unit, $this->petugas, 'Hasil skrining ulang bersih');
        $this->assertSame(BloodUnit::STATUS_TERSEDIA, $unit->status);
    }

    #[Test]
    public function unit_yang_ditolak_tidak_bisa_dirilis(): void
    {
        $unit = $this->units->reject($this->ambilUnit(), $this->petugas, 'Hasil skrining HBsAg reaktif');

        $this->expectException(BloodException::class);

        $this->units->release($unit, $this->petugas);
    }

    #[Test]
    public function setiap_perpindahan_status_tercatat_di_jejak_audit(): void
    {
        $unit = $this->units->release($this->ambilUnit(), $this->petugas);
        $this->units->hold($unit, $this->petugas, 'Cek ulang');

        $this->assertDatabaseCount('blood.unit_status_logs', 2);
        $this->assertDatabaseHas('blood.unit_status_logs', [
            'blood_unit_id' => $unit->id, 'from_status' => 'karantina', 'to_status' => 'tersedia',
        ]);
        $this->assertDatabaseHas('blood.unit_status_logs', [
            'blood_unit_id' => $unit->id, 'from_status' => 'tersedia', 'to_status' => 'ditahan', 'reason' => 'Cek ulang',
        ]);
    }

    #[Test]
    public function layar_utd_hanya_untuk_petugas_utd(): void
    {
        $this->actingAs($this->petugas)->get(route('blood.pendonor.index'))->assertOk();
        $this->actingAs($this->petugas)->get(route('blood.stok.index'))->assertOk();

        $dokter = User::query()->create([
            'username' => 'uji-dokter-utd', 'name' => 'Dokter Uji', 'password' => 'password', 'is_active' => true,
        ]);
        $dokter->roles()->attach(Role::query()->where('code', 'dokter')->firstOrFail());

        $this->actingAs($dokter)->get(route('blood.stok.index'))->assertForbidden();
    }

    // ------------------------------------------------------------------ bantu

    private function daftarkanPendonor(): \App\Modules\Blood\Models\Donor
    {
        return $this->donors->register([
            'name' => 'Pendonor Uji', 'blood_type' => 'O', 'rhesus' => '+', 'sex' => 'L', 'birth_date' => '1995-01-01',
        ]);
    }

    private function ambilUnit(array $override = []): BloodUnit
    {
        return $this->units->collect(array_merge([
            'donor_id' => $this->daftarkanPendonor()->id,
            'blood_type' => 'O',
            'rhesus' => '+',
            'component' => 'whole-blood',
            'volume_ml' => 350,
            'collected_at' => now(),
            'expiry_date' => now()->addDays(35)->toDateString(),
        ], $override));
    }
}
