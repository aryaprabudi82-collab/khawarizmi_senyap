<?php

namespace Tests\Feature\Reporting;

use App\Modules\Billing\Services\InvoiceService;
use App\Modules\Catalog\Models\Payer;
use App\Modules\Clinical\Models\Assessment;
use App\Modules\Clinical\Services\ClinicalRecordService;
use App\Modules\Encounter\Models\Registration;
use App\Modules\Encounter\Services\RegistrationService;
use App\Modules\Identity\Services\PatientRegistry;
use App\Modules\Order\Database\Seeders\TestCatalogSeeder;
use App\Modules\Organization\Models\Unit;
use App\Modules\Platform\Database\Seeders\PermissionCatalogSeeder;
use App\Modules\Platform\Database\Seeders\RoleSeeder;
use App\Modules\Platform\Models\Role;
use App\Modules\Platform\Models\User;
use App\Modules\Reporting\Models\DailyRevenueSummary;
use App\Modules\Reporting\Models\DailyVisitSummary;
use App\Modules\Reporting\Models\DiagnosisFrequency;
use App\Modules\Reporting\Services\ReportingSyncService;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ReportingSyncTest extends TestCase
{
    use RefreshDatabase;

    private ReportingSyncService $sync;
    private User $manajemen;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([PermissionCatalogSeeder::class, RoleSeeder::class, ReferenceDataSeeder::class, TestCatalogSeeder::class]);

        $this->sync = app(ReportingSyncService::class);

        $this->manajemen = User::query()->create([
            'username' => 'uji-manajemen', 'name' => 'Manajemen Uji', 'password' => 'password', 'is_active' => true,
        ]);
        $this->manajemen->roles()->attach(Role::query()->where('code', 'manajemen')->firstOrFail());
    }

    #[Test]
    public function sinkron_menghitung_kunjungan_per_unit_dan_penjamin(): void
    {
        $this->daftarkan('UMUM');
        $this->daftarkan('UMUM');
        $this->daftarkan('BPJS');

        $hasil = $this->sync->syncDay(now());

        $this->assertSame(2, $hasil['kunjungan']); // 2 kelompok: umum & bpjs, unit sama

        $umum = DailyVisitSummary::query()->where('payer_kind', 'umum')->firstOrFail();
        $bpjs = DailyVisitSummary::query()->where('payer_kind', 'bpjs')->firstOrFail();

        $this->assertSame(2, $umum->visit_count);
        $this->assertSame(1, $bpjs->visit_count);
        $this->assertSame('Poliklinik Umum', $umum->unit_name);
    }

    #[Test]
    public function sinkron_ulang_hari_yang_sama_menimpa_bukan_menambah(): void
    {
        $this->daftarkan('UMUM');
        $this->sync->syncDay(now());
        $this->daftarkan('UMUM');

        $this->sync->syncDay(now());

        $this->assertSame(2, DailyVisitSummary::query()->where('payer_kind', 'umum')->value('visit_count'));
        $this->assertSame(1, DailyVisitSummary::query()->where('payer_kind', 'umum')->count());
    }

    #[Test]
    public function sinkron_menghitung_frekuensi_diagnosis(): void
    {
        $satu = $this->daftarkan('UMUM');
        $dua = $this->daftarkan('UMUM');
        $this->catatDiagnosis($satu, 'A09', 'Diare dan gastroenteritis');
        $this->catatDiagnosis($dua, 'A09', 'Diare dan gastroenteritis');

        $hasil = $this->sync->syncDay(now());

        $this->assertSame(1, $hasil['diagnosis']);
        $frekuensi = DiagnosisFrequency::query()->where('code', 'A09')->firstOrFail();
        $this->assertSame(2, $frekuensi->occurrence_count);
    }

    #[Test]
    public function sinkron_menghitung_pendapatan_per_penjamin(): void
    {
        $registrasi = $this->daftarkan('UMUM');
        $tagihan = app(InvoiceService::class)->openInvoice($registrasi->id);
        app(InvoiceService::class)->pay($tagihan, 50000, 'tunai');

        $hasil = $this->sync->syncDay(now());

        $this->assertSame(1, $hasil['pendapatan']);
        $rekap = DailyRevenueSummary::query()->where('payer_kind', 'umum')->firstOrFail();
        $this->assertEqualsWithDelta(50000.0, (float) $rekap->total_amount, 0.001);
        $this->assertSame(1, $rekap->invoice_count);
    }

    #[Test]
    public function dashboard_menampilkan_hasil_setelah_disinkronkan_lewat_tombol(): void
    {
        $this->daftarkan('UMUM');
        $tanggal = now()->toDateString();

        $this->actingAs($this->manajemen)
            ->post(route('reporting.sinkron'), ['tanggal' => $tanggal])
            ->assertRedirect()
            ->assertSessionHas('sukses');

        $this->actingAs($this->manajemen)
            ->get(route('reporting.dashboard', ['tanggal' => $tanggal]))
            ->assertOk()
            ->assertSee('Poliklinik Umum');
    }

    #[Test]
    public function layar_laporan_hanya_untuk_manajemen(): void
    {
        $this->actingAs($this->manajemen)->get(route('reporting.dashboard'))->assertOk();

        $dokter = User::query()->create([
            'username' => 'uji-dokter-laporan', 'name' => 'Dokter Uji', 'password' => 'password', 'is_active' => true,
        ]);
        $dokter->roles()->attach(Role::query()->where('code', 'dokter')->firstOrFail());

        $this->actingAs($dokter)->get(route('reporting.dashboard'))->assertForbidden();
    }

    // ------------------------------------------------------------------ bantu

    private function daftarkan(string $kodePenjamin): Registration
    {
        static $urut = 0;
        $urut++;

        $pasien = app(PatientRegistry::class)->register([
            'name' => 'Pasien Reporting Uji ' . $urut, 'sex' => 'L', 'birth_date' => '1990-01-01',
        ]);

        return app(RegistrationService::class)->register(
            patientId: $pasien->id,
            unitId: Unit::query()->where('code', 'POL-UMUM')->value('id'),
            payerId: Payer::query()->where('code', $kodePenjamin)->value('id'),
        );
    }

    private function catatDiagnosis(Registration $registrasi, string $code, string $display): void
    {
        $clinical = app(ClinicalRecordService::class);
        $assessment = $clinical->openAssessment($registrasi->id, Assessment::KIND_SOAP);
        $clinical->addDiagnosis($assessment, $code, $display, 'sekunder', 'kerja');
    }
}
