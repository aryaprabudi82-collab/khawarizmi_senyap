<?php

namespace Tests\Feature\Pharmacy;

use App\Modules\Catalog\Models\Payer;
use App\Modules\Encounter\Models\Registration;
use App\Modules\Encounter\Services\RegistrationService;
use App\Modules\Identity\Services\PatientRegistry;
use App\Modules\Organization\Models\Unit;
use App\Modules\Pharmacy\Database\Seeders\PharmacySeeder;
use App\Modules\Pharmacy\Models\Drug;
use App\Modules\Pharmacy\Models\ExternalPrescription;
use App\Modules\Pharmacy\Models\PatientStockRequest;
use App\Modules\Pharmacy\Models\StockBatch;
use App\Modules\Pharmacy\Models\StockLocation;
use App\Modules\Pharmacy\Models\WardStockRequest;
use App\Modules\Pharmacy\Services\ExternalPrescriptionService;
use App\Modules\Pharmacy\Services\PatientStockRequestService;
use App\Modules\Pharmacy\Services\PharmacyException;
use App\Modules\Pharmacy\Services\ProcedureBhpUsageService;
use App\Modules\Pharmacy\Services\WardStockRequestService;
use App\Modules\Platform\Database\Seeders\PermissionCatalogSeeder;
use App\Modules\Platform\Database\Seeders\RoleSeeder;
use App\Modules\Platform\Models\Role;
use App\Modules\Platform\Models\User;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class DispensingTest extends TestCase
{
    use RefreshDatabase;

    private WardStockRequestService $ward;
    private PatientStockRequestService $patient;
    private ExternalPrescriptionService $external;
    private ProcedureBhpUsageService $bhpOk;

    private User $apoteker;
    private Drug $obat;
    private StockLocation $depo;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([PermissionCatalogSeeder::class, RoleSeeder::class, ReferenceDataSeeder::class, PharmacySeeder::class]);

        $this->ward = app(WardStockRequestService::class);
        $this->patient = app(PatientStockRequestService::class);
        $this->external = app(ExternalPrescriptionService::class);
        $this->bhpOk = app(ProcedureBhpUsageService::class);

        $this->apoteker = User::query()->create([
            'username' => 'uji-dispensing', 'name' => 'Apoteker Uji', 'password' => 'password', 'is_active' => true,
        ]);
        $this->apoteker->roles()->attach(Role::query()->where('code', 'apoteker')->firstOrFail());

        $this->obat = Drug::query()->where('code', 'OBT-001')->firstOrFail();
        $this->depo = StockLocation::query()->where('code', 'DEPO-RJ')->firstOrFail();
    }

    #[Test]
    public function permintaan_ruangan_dikeluarkan_mengurangi_stok_depo(): void
    {
        $stokAwal = (float) StockBatch::where('location_id', $this->depo->id)->where('drug_id', $this->obat->id)->sum('quantity_on_hand');

        $unit = Unit::query()->where('code', 'FARMASI')->firstOrFail();
        $permintaan = $this->ward->request($unit->id, $unit->name, [$this->obat->id => 20], 'Kebutuhan poli', $this->apoteker->id);
        $dikeluarkan = $this->ward->issue($permintaan, $this->apoteker);

        $stokAkhir = (float) StockBatch::where('location_id', $this->depo->id)->where('drug_id', $this->obat->id)->sum('quantity_on_hand');

        $this->assertSame(WardStockRequest::STATUS_DIKELUARKAN, $dikeluarkan->status);
        $this->assertEqualsWithDelta($stokAwal - 20, $stokAkhir, 0.01);
    }

    #[Test]
    public function permintaan_ruangan_bisa_untuk_unit_utd(): void
    {
        $utd = Unit::query()->create(['code' => 'UTD-01', 'name' => 'UTD/Bank Darah', 'kind' => 'penunjang', 'is_active' => true]);

        $permintaan = $this->ward->request($utd->id, $utd->name, [$this->obat->id => 5], 'Reagen transfusi', $this->apoteker->id);

        $this->assertSame('UTD/Bank Darah', $permintaan->unit_name);
    }

    #[Test]
    public function permintaan_stok_pasien_terikat_registrasi_dan_mengurangi_stok(): void
    {
        $registrasi = $this->daftarkan();

        $permintaan = $this->patient->request($registrasi->id, [$this->obat->id => 3], null, $this->apoteker->id);

        $this->assertSame($registrasi->patient_mrn, $permintaan->patient_mrn);
        $this->assertSame(PatientStockRequest::STATUS_DIAJUKAN, $permintaan->status);

        $dikeluarkan = $this->patient->issue($permintaan, $this->apoteker);
        $this->assertSame(PatientStockRequest::STATUS_DIKELUARKAN, $dikeluarkan->status);

        $riwayat = $this->patient->forRegistration($registrasi->id);
        $this->assertCount(1, $riwayat);
    }

    #[Test]
    public function permintaan_stok_pasien_untuk_registrasi_tak_dikenal_ditolak(): void
    {
        $this->expectException(PharmacyException::class);
        $this->patient->request(999999, [$this->obat->id => 1], null, $this->apoteker->id);
    }

    #[Test]
    public function resep_luar_diserahkan_memotong_stok_dan_menghitung_total(): void
    {
        $resep = $this->external->receive([
            'patient_name' => 'Pasien Walk-in', 'prescriber_name' => 'dr. Luar',
            'issued_date' => now()->toDateString(),
        ], [$this->obat->id => ['quantity' => 4, 'unit_price' => 1500]], $this->apoteker->id);

        $this->assertEqualsWithDelta(6000.0, (float) $resep->total_amount, 0.01);

        $diserahkan = $this->external->dispense($resep, $this->apoteker);
        $this->assertSame(ExternalPrescription::STATUS_DISERAHKAN, $diserahkan->status);
    }

    #[Test]
    public function resep_luar_yang_sudah_diserahkan_tidak_bisa_dibatalkan(): void
    {
        $resep = $this->external->receive([
            'patient_name' => 'Pasien Walk-in 2', 'prescriber_name' => 'dr. Luar',
            'issued_date' => now()->toDateString(),
        ], [$this->obat->id => ['quantity' => 1, 'unit_price' => 1500]], $this->apoteker->id);
        $this->external->dispense($resep, $this->apoteker);

        $this->expectException(PharmacyException::class);
        $this->external->cancel($resep);
    }

    #[Test]
    public function penggunaan_bhp_ok_langsung_memotong_stok(): void
    {
        $stokAwal = (float) StockBatch::where('location_id', $this->depo->id)->where('drug_id', $this->obat->id)->sum('quantity_on_hand');

        $penggunaan = $this->bhpOk->record([
            'room' => 'OK', 'patient_name' => 'Pasien Operasi', 'patient_mrn' => null, 'operation_id' => null, 'notes' => null,
        ], [$this->obat->id => 2], $this->apoteker);

        $stokAkhir = (float) StockBatch::where('location_id', $this->depo->id)->where('drug_id', $this->obat->id)->sum('quantity_on_hand');

        $this->assertSame('OK', $penggunaan->room);
        $this->assertEqualsWithDelta($stokAwal - 2, $stokAkhir, 0.01);
    }

    #[Test]
    public function layar_permintaan_ruangan_pasien_resep_luar_dan_bhp_ok_hanya_untuk_apoteker(): void
    {
        $this->actingAs($this->apoteker)->get(route('pharmacy.permintaan-ruangan.index'))->assertOk();
        $this->actingAs($this->apoteker)->get(route('pharmacy.permintaan-pasien.index'))->assertOk();
        $this->actingAs($this->apoteker)->get(route('pharmacy.resep-luar.index'))->assertOk();
        $this->actingAs($this->apoteker)->get(route('pharmacy.bhp-ok.index'))->assertOk();

        $dokter = User::query()->create([
            'username' => 'uji-dokter-dispensing', 'name' => 'Dokter Uji', 'password' => 'password', 'is_active' => true,
        ]);
        $dokter->roles()->attach(Role::query()->where('code', 'dokter')->firstOrFail());

        $this->actingAs($dokter)->get(route('pharmacy.permintaan-ruangan.index'))->assertForbidden();
        $this->actingAs($dokter)->get(route('pharmacy.permintaan-pasien.index'))->assertForbidden();
        $this->actingAs($dokter)->get(route('pharmacy.resep-luar.index'))->assertForbidden();
        $this->actingAs($dokter)->get(route('pharmacy.bhp-ok.index'))->assertForbidden();
    }

    #[Test]
    public function form_resep_luar_bisa_disubmit_lewat_http(): void
    {
        $this->actingAs($this->apoteker)->post(route('pharmacy.resep-luar.simpan'), [
            'patient_name' => 'Pasien HTTP', 'prescriber_name' => 'dr. HTTP', 'issued_date' => now()->toDateString(),
            'drug_id' => [$this->obat->id], 'quantity' => [2], 'unit_price' => [1500],
        ])->assertRedirect();

        $this->assertDatabaseHas('pharmacy.external_prescriptions', ['patient_name' => 'Pasien HTTP']);
    }

    #[Test]
    public function filter_kind_pulang_pada_antrean_resep_hanya_menampilkan_resep_pulang(): void
    {
        $registrasi = $this->daftarkan();
        app(\App\Modules\Pharmacy\Services\PrescriptionService::class)->create($registrasi->id, $this->apoteker, 'rawat-jalan');
        app(\App\Modules\Pharmacy\Services\PrescriptionService::class)->create($registrasi->id, $this->apoteker, 'pulang');

        $respons = $this->actingAs($this->apoteker)->get(route('resep.index', ['kind' => 'pulang']));

        $respons->assertOk();
        $daftar = $respons->viewData('daftar');
        $this->assertTrue($daftar->every(fn ($r) => $r->kind === 'pulang'));
        $this->assertGreaterThanOrEqual(1, $daftar->count());
    }

    private function daftarkan(string $nama = 'Pasien Dispensing'): Registration
    {
        static $urut = 0;
        $urut++;

        $pasien = app(PatientRegistry::class)->register([
            'name' => $nama . ' ' . $urut, 'sex' => 'L', 'birth_date' => '1990-01-01',
        ]);

        return app(RegistrationService::class)->register(
            patientId: $pasien->id,
            unitId: Unit::query()->where('code', 'POL-UMUM')->value('id'),
            payerId: Payer::query()->where('code', 'UMUM')->value('id'),
        );
    }
}
