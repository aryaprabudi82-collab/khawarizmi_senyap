<?php

namespace Tests\Feature\Billing;

use App\Modules\Catalog\Models\Payer;
use App\Modules\Catalog\Models\Service;
use App\Modules\Catalog\Models\Tariff;
use App\Modules\Clinical\Models\Procedure;
use App\Modules\Clinical\Services\ClinicalRecordService;
use App\Modules\Encounter\Models\Registration;
use App\Modules\Encounter\Services\RegistrationService;
use App\Modules\Identity\Services\PatientRegistry;
use App\Modules\Organization\Models\Practitioner;
use App\Modules\Organization\Models\Unit;
use App\Modules\Platform\Database\Seeders\PermissionCatalogSeeder;
use App\Modules\Platform\Database\Seeders\RoleSeeder;
use App\Modules\Platform\Models\Role;
use App\Modules\Platform\Models\User;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Domain I item C: komponen jasa medis.
 *
 * Enam komponen pada tarif (cermin jns_perawatan Khanza) dibekukan ke tiap
 * tindakan saat dilakukan, lalu jadi dasar ~14 kode laporan harian_*,
 * bulanan_*, dan rekap JM.
 */
class MedicalFeeTest extends TestCase
{
    use RefreshDatabase;

    private ClinicalRecordService $klinis;
    private RegistrationService $registrations;
    private User $manajemen;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([PermissionCatalogSeeder::class, RoleSeeder::class, ReferenceDataSeeder::class]);

        $this->klinis = app(ClinicalRecordService::class);
        $this->registrations = app(RegistrationService::class);

        $this->manajemen = User::query()->create([
            'username' => 'uji-manajemen-jm', 'name' => 'Manajemen Uji', 'password' => 'password', 'is_active' => true,
        ]);
        $this->manajemen->roles()->attach(Role::query()->where('code', 'manajemen')->firstOrFail());
    }

    #[Test]
    public function komponen_tarif_wajib_menjumlah_sama_dengan_tarifnya(): void
    {
        $this->expectException(\Illuminate\Database\QueryException::class);

        $this->buatTarif('UJI-SALAH', 100_000, [
            'share_doctor' => 40_000,
            'share_facility' => 50_000, // total 90.000, bukan 100.000
        ]);
    }

    #[Test]
    public function tarif_boleh_belum_dirinci_komponennya(): void
    {
        $tarif = $this->buatTarif('UJI-KOSONG', 100_000, []);

        $this->assertSame(0.0, (float) $tarif->share_doctor);
        $this->assertSame(100_000.0, (float) $tarif->amount, 'Tarif tanpa rincian tetap sah dan tetap bisa ditagihkan');
    }

    #[Test]
    public function porsi_jasa_dibekukan_ke_tindakan_saat_dicatat(): void
    {
        $this->buatTarif('UJI-BEKU', 200_000, [
            'share_doctor' => 80_000,
            'share_paramedic' => 20_000,
            'share_facility' => 60_000,
            'share_bhp' => 10_000,
            'share_kso' => 15_000,
            'share_management' => 15_000,
        ]);

        $tindakan = $this->catatTindakan('UJI-BEKU');

        $this->assertSame(80_000.0, (float) $tindakan->share_doctor);
        $this->assertSame(20_000.0, (float) $tindakan->share_paramedic);
        $this->assertSame(200_000.0, (float) $tindakan->amount);
    }

    #[Test]
    public function porsi_dikali_kuantitas(): void
    {
        $this->buatTarif('UJI-QTY', 100_000, [
            'share_doctor' => 60_000,
            'share_facility' => 40_000,
        ]);

        $tindakan = $this->catatTindakan('UJI-QTY', quantity: 3);

        $this->assertSame(180_000.0, (float) $tindakan->share_doctor);
        $this->assertSame(120_000.0, (float) $tindakan->share_facility);
        $this->assertSame(300_000.0, (float) $tindakan->amount);
    }

    #[Test]
    public function tarif_naik_tidak_mengubah_remunerasi_tindakan_yang_sudah_lewat(): void
    {
        $tarif = $this->buatTarif('UJI-NAIK', 100_000, [
            'share_doctor' => 50_000,
            'share_facility' => 50_000,
        ]);

        $tindakan = $this->catatTindakan('UJI-NAIK');
        $this->assertSame(50_000.0, (float) $tindakan->share_doctor);

        // Tarif direvisi naik dua kali lipat.
        $tarif->update(['amount' => 200_000, 'share_doctor' => 100_000, 'share_facility' => 100_000]);

        $this->assertSame(
            50_000.0,
            (float) $tindakan->refresh()->share_doctor,
            'Jasa dokter yang sudah dibekukan tidak boleh ikut naik'
        );
    }

    #[Test]
    public function tindakan_dengan_tarif_belum_dirinci_menghasilkan_porsi_nol(): void
    {
        $this->buatTarif('UJI-NOL', 150_000, []);

        $tindakan = $this->catatTindakan('UJI-NOL');

        $this->assertSame(0.0, (float) $tindakan->share_doctor);
        $this->assertSame(150_000.0, (float) $tindakan->amount, 'Tetap tertagih penuh meski komponennya belum diisi');
    }

    #[Test]
    public function view_terbitan_clinical_memaparkan_porsi_untuk_billing(): void
    {
        $this->buatTarif('UJI-VIEW', 100_000, ['share_doctor' => 70_000, 'share_facility' => 30_000]);
        $this->catatTindakan('UJI-VIEW');

        $baris = DB::table('clinical.v_procedure_charge')->first();

        $this->assertSame(70_000.0, (float) $baris->share_doctor);
        $this->assertNotNull($baris->practitioner_id, 'Rekap per dokter butuh pelaksananya');
    }

    #[Test]
    public function rekap_per_pelaksana_menjumlahkan_jasa_dokter(): void
    {
        $this->buatTarif('UJI-REKAP', 100_000, ['share_doctor' => 60_000, 'share_facility' => 40_000]);
        $this->catatTindakan('UJI-REKAP');
        $this->catatTindakan('UJI-REKAP');

        $rekap = app(\App\Modules\Billing\Services\MedicalFeeReportService::class)
            ->perPractitioner('share_doctor', now()->toDateString(), now()->toDateString());

        $this->assertCount(1, $rekap);
        $this->assertSame(120_000.0, (float) $rekap->first()->jumlah);
        $this->assertSame(2, (int) $rekap->first()->jumlah_tindakan);
    }

    /**
     * fee_ralan dan fee_visit_dokter ternyata bukan jenis fee baru,
     * melainkan jasa dokter yang sama disaring per jenis rawat.
     */
    #[Test]
    public function fee_ralan_dan_fee_visit_dokter_dilayani_penyaring_jenis_rawat(): void
    {
        $this->buatTarif('UJI-FILTER', 100_000, ['share_doctor' => 60_000, 'share_facility' => 40_000]);

        $this->catatTindakan('UJI-FILTER');                       // rawat jalan
        $this->catatTindakan('UJI-FILTER', careType: 'ranap');    // rawat inap

        $laporan = app(\App\Modules\Billing\Services\MedicalFeeReportService::class);
        $hariIni = now()->toDateString();

        $ralan = $laporan->perPractitioner('share_doctor', $hariIni, $hariIni, careType: 'ralan');
        $ranap = $laporan->perPractitioner('share_doctor', $hariIni, $hariIni, careType: 'ranap');
        $semua = $laporan->perPractitioner('share_doctor', $hariIni, $hariIni);

        $this->assertSame(60_000.0, (float) $ralan->first()->jumlah, 'fee_ralan');
        $this->assertSame(60_000.0, (float) $ranap->first()->jumlah, 'fee_visit_dokter');
        $this->assertSame(120_000.0, (float) $semua->first()->jumlah, 'Tanpa penyaring, keduanya terhitung');
    }

    /** fee_bacaan_ekg — jasa dokter yang sama disaring per kode layanan. */
    #[Test]
    public function fee_bacaan_ekg_dilayani_penyaring_kode_layanan(): void
    {
        $this->buatTarif('EKG', 80_000, ['share_doctor' => 50_000, 'share_facility' => 30_000]);
        $this->buatTarif('UJI-LAIN', 100_000, ['share_doctor' => 70_000, 'share_facility' => 30_000]);

        $this->catatTindakan('EKG');
        $this->catatTindakan('UJI-LAIN');

        $hariIni = now()->toDateString();
        $ekg = app(\App\Modules\Billing\Services\MedicalFeeReportService::class)
            ->perPractitioner('share_doctor', $hariIni, $hariIni, serviceCode: 'EKG');

        $this->assertSame(50_000.0, (float) $ekg->first()->jumlah);
        $this->assertSame(1, (int) $ekg->first()->jumlah_tindakan);
    }

    #[Test]
    public function penyaring_juga_berlaku_pada_ringkasan_harian_dan_bulanan(): void
    {
        $this->buatTarif('UJI-SEMUA', 100_000, ['share_doctor' => 60_000, 'share_facility' => 40_000]);
        $this->catatTindakan('UJI-SEMUA');
        $this->catatTindakan('UJI-SEMUA', careType: 'ranap');

        $laporan = app(\App\Modules\Billing\Services\MedicalFeeReportService::class);
        $hariIni = now()->toDateString();

        $ringkasan = $laporan->summary($hariIni, $hariIni, careType: 'ralan');
        $harian = $laporan->daily('share_doctor', $hariIni, $hariIni, careType: 'ralan');
        $bulanan = $laporan->monthly('share_doctor', (int) now()->year, careType: 'ralan');

        $this->assertSame(60_000.0, $ringkasan->firstWhere('label', 'Jasa Dokter')['jumlah']);
        $this->assertSame(60_000.0, (float) $harian->first()->jumlah);
        $this->assertSame(60_000.0, (float) $bulanan->first()->jumlah, 'monthly() ikut menghormati penyaring, tidak melewatinya');
    }
    #[Test]
    public function komponen_yang_tidak_dikenal_ditolak_bukan_diteruskan_ke_sql(): void
    {
        $this->expectException(\App\Modules\Billing\Services\BillingException::class);

        app(\App\Modules\Billing\Services\MedicalFeeReportService::class)
            ->perPractitioner('share_doctor; drop table catalog.tariffs', '2026-01-01', '2026-12-31');
    }

    #[Test]
    public function layar_rekap_jasa_medis_bukan_untuk_kasir(): void
    {
        $this->actingAs($this->manajemen)->get(route('jasa-medis.index'))->assertOk();

        $kasir = User::query()->create([
            'username' => 'uji-kasir-jm', 'name' => 'Kasir Uji', 'password' => 'password', 'is_active' => true,
        ]);
        $kasir->roles()->attach(Role::query()->where('code', 'kasir')->firstOrFail());

        $this->actingAs($kasir)->get(route('jasa-medis.index'))->assertForbidden();
    }

    // ------------------------------------------------------------------ bantu

    private function buatTarif(string $kode, float $nilai, array $komponen): Tariff
    {
        $layanan = Service::query()->create([
            'code' => $kode, 'name' => 'Tindakan ' . $kode, 'category' => 'tindakan', 'is_active' => true,
        ]);

        return Tariff::query()->create([
            'service_id' => $layanan->id,
            'payer_id' => Payer::query()->where('code', 'UMUM')->value('id'),
            'care_class' => '-',
            'amount' => $nilai,
            'valid_from' => now()->subYear()->toDateString(),
        ] + $komponen);
    }

    private function catatTindakan(string $kodeLayanan, float $quantity = 1, string $careType = 'ralan'): Procedure
    {
        return $this->klinis->recordProcedure(
            registrationId: $this->daftarkan($careType)->id,
            serviceCode: $kodeLayanan,
            quantity: $quantity,
            note: null,
        );
    }

    private function daftarkan(string $careType = 'ralan'): Registration
    {
        static $urut = 0;
        $urut++;

        $pasien = app(PatientRegistry::class)->register([
            'name' => 'Pasien JM ' . $urut, 'sex' => 'L', 'birth_date' => '1990-01-01',
        ]);

        return $this->registrations->register(
            patientId: $pasien->id,
            unitId: Unit::query()->where('code', 'POL-UMUM')->firstOrFail()->id,
            payerId: Payer::query()->where('code', 'UMUM')->value('id'),
            practitionerId: Practitioner::query()->where('is_active', true)->value('id'),
            extra: $careType === 'ranap' ? ['care_type' => 'ranap'] : [],
        );
    }
}
