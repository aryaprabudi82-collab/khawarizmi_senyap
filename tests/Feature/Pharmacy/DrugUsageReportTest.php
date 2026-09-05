<?php

namespace Tests\Feature\Pharmacy;

use App\Modules\Catalog\Models\Payer;
use App\Modules\Clinical\Database\Seeders\DiagnosisCodeSeeder;
use App\Modules\Encounter\Models\Registration;
use App\Modules\Encounter\Services\RegistrationService;
use App\Modules\Identity\Services\PatientRegistry;
use App\Modules\Organization\Models\Unit;
use App\Modules\Pharmacy\Database\Seeders\PharmacySeeder;
use App\Modules\Pharmacy\Models\Drug;
use App\Modules\Pharmacy\Models\Prescription;
use App\Modules\Pharmacy\Models\StockLocation;
use App\Modules\Pharmacy\Services\DrugUsageReportService;
use App\Modules\Pharmacy\Services\PharmacyRecapService;
use App\Modules\Pharmacy\Services\PrescriptionService;
use App\Modules\Pharmacy\Services\StockLedger;
use App\Modules\Pharmacy\Services\WardStockRequestService;
use App\Modules\Platform\Database\Seeders\PermissionCatalogSeeder;
use App\Modules\Platform\Database\Seeders\RoleSeeder;
use App\Modules\Platform\Models\Role;
use App\Modules\Platform\Models\User;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class DrugUsageReportTest extends TestCase
{
    use RefreshDatabase;

    private PrescriptionService $prescriptions;
    private DrugUsageReportService $reports;
    private PharmacyRecapService $recap;
    private WardStockRequestService $wardRequests;

    private User $apoteker;
    private User $dokter;
    private Drug $obat;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([
            PermissionCatalogSeeder::class, RoleSeeder::class, ReferenceDataSeeder::class,
            DiagnosisCodeSeeder::class, PharmacySeeder::class,
        ]);

        $this->prescriptions = app(PrescriptionService::class);
        $this->reports = app(DrugUsageReportService::class);
        $this->recap = app(PharmacyRecapService::class);
        $this->wardRequests = app(WardStockRequestService::class);

        $this->apoteker = User::query()->create([
            'username' => 'uji-laporan-obat', 'name' => 'Apoteker Uji', 'password' => 'password', 'is_active' => true,
        ]);
        $this->apoteker->roles()->attach(Role::query()->where('code', 'apoteker')->firstOrFail());

        $this->dokter = User::query()->create([
            'username' => 'uji-dokter-laporan-obat', 'name' => 'Dokter Uji', 'password' => 'password', 'is_active' => true,
        ]);
        $this->dokter->roles()->attach(Role::query()->where('code', 'dokter')->firstOrFail());

        $this->obat = Drug::query()->where('code', 'OBT-001')->firstOrFail();
    }

    #[Test]
    public function laporan_mengelompokkan_per_pasien_dokter_dan_unit(): void
    {
        $resep = $this->resepDiserahkan();

        $perPasien = $this->reports->byPatient(now()->subDay()->toDateString(), now()->addDay()->toDateString());
        $perDokter = $this->reports->byPrescriber(now()->subDay()->toDateString(), now()->addDay()->toDateString());
        $perUnit = $this->reports->byUnit(now()->subDay()->toDateString(), now()->addDay()->toDateString());
        $perObat = $this->reports->byDrug(now()->subDay()->toDateString(), now()->addDay()->toDateString());
        $top10 = $this->reports->top10(now()->subDay()->toDateString(), now()->addDay()->toDateString());

        $this->assertTrue($perPasien->contains(fn ($p) => $p->patient_mrn === $resep->patient_mrn));
        // Registrasi uji tidak menetapkan practitioner_id, jadi prescriber_name null — dikelompokkan sebagai '—' (lihat coalesce di byPrescriber()).
        $this->assertTrue($perDokter->contains(fn ($d) => $d->prescriber_name === ($resep->prescriber_name ?? '—')));
        $this->assertTrue($perUnit->contains(fn ($u) => $u->unit_name === $resep->unit_name));
        $this->assertTrue($perObat->contains(fn ($o) => $o->drug_name === $this->obat->name));
        $this->assertTrue($top10->contains(fn ($t) => $t->drug_name === $this->obat->name));
    }

    #[Test]
    public function biaya_per_tanggal_menjumlah_per_pasien_per_hari(): void
    {
        $resep = $this->resepDiserahkan();

        $biaya = $this->reports->biayaPerTanggal(now()->subDay()->toDateString(), now()->addDay()->toDateString());

        $baris = $biaya->firstWhere('patient_mrn', $resep->patient_mrn);
        $this->assertNotNull($baris);
        $this->assertGreaterThan(0, (float) $baris->total_biaya);
    }

    #[Test]
    public function rekap_permintaan_ruangan_menghitung_status(): void
    {
        $unit = Unit::query()->where('code', 'FARMASI')->firstOrFail();
        $permintaan = $this->wardRequests->request($unit->id, $unit->name, [$this->obat->id => 5], null, $this->apoteker->id);
        $this->wardRequests->issue($permintaan, $this->apoteker);

        $ringkasan = $this->recap->permintaanRuanganRingkasan(now()->subDay()->toDateString(), now()->addDay()->toDateString());

        $this->assertSame(1, $ringkasan['jumlah']);
        $this->assertSame(1, $ringkasan['jumlah_dikeluarkan']);
    }

    #[Test]
    public function layar_laporan_penggunaan_obat_hanya_untuk_apoteker(): void
    {
        $this->actingAs($this->apoteker)->get(route('pharmacy.laporan-obat.index'))->assertOk();
        $this->actingAs($this->dokter)->get(route('pharmacy.laporan-obat.index'))->assertForbidden();
    }

    /**
     * Domain I item D: enam kode obat_per_* ditandai katalog context=billing,
     * tapi datanya resep dan laporannya sudah ada di sini — dilayani penyaring
     * di layar yang sama, bukan layar kembar di billing (dikonfirmasi user).
     */
    #[Test]
    public function obat_per_dokter_dipisahkan_menurut_jenis_rawat(): void
    {
        $this->resepDiserahkan(careType: 'ralan');
        $this->resepDiserahkan(careType: 'ranap');

        $hariIni = now()->toDateString();

        $ralan = $this->reports->byPrescriber($hariIni, $hariIni, 'ralan');
        $ranap = $this->reports->byPrescriber($hariIni, $hariIni, 'ranap');
        $semua = $this->reports->byPrescriber($hariIni, $hariIni);

        $this->assertSame(1, (int) $ralan->first()->jumlah_resep, 'obat_per_dokter_ralan');
        $this->assertSame(1, (int) $ranap->first()->jumlah_resep, 'obat_per_dokter_ranap');
        $this->assertSame(2, (int) $semua->first()->jumlah_resep, 'obat_per_dokter_peresep, tanpa penyaring');
    }

    /** obat_per_kamar — rekap per unit disaring ke rawat inap. */
    #[Test]
    public function obat_per_unit_bisa_disaring_ke_rawat_inap(): void
    {
        $this->resepDiserahkan(careType: 'ralan');
        $this->resepDiserahkan(careType: 'ranap');

        $hariIni = now()->toDateString();

        $this->assertSame(1, (int) $this->reports->byUnit($hariIni, $hariIni, 'ranap')->first()->jumlah_resep);
        $this->assertSame(2, (int) $this->reports->byUnit($hariIni, $hariIni)->first()->jumlah_resep);
    }

    /** obat_per_cara_bayar — dikelompokkan per penjamin. */
    #[Test]
    public function obat_dikelompokkan_per_cara_bayar(): void
    {
        $this->resepDiserahkan(penjamin: 'UMUM');
        $this->resepDiserahkan(penjamin: 'BPJS');

        $rekap = $this->reports->byPayer(now()->toDateString(), now()->toDateString());

        $this->assertCount(2, $rekap);
        $this->assertEqualsCanonicalizing(
            ['Umum / Bayar Sendiri', 'BPJS Kesehatan'],
            $rekap->pluck('payer_name')->all()
        );
    }
    /**
     * Penyaring yang tampil di layar harus berlaku untuk SELURUH kartu,
     * bukan sebagian. Sebelumnya jenis rawat hanya mengenai dua kartu
     * sementara lima lainnya diam-diam menampilkan semua data — pengguna
     * yang memilih "Rawat Inap" akan membaca angka rawat jalan tanpa tahu.
     */
    #[Test]
    public function penyaring_jenis_rawat_berlaku_untuk_semua_kartu(): void
    {
        $this->resepDiserahkan(careType: 'ralan');
        $this->resepDiserahkan(careType: 'ranap');

        $hariIni = now()->toDateString();

        $semua = [
            'byPatient' => $this->reports->byPatient($hariIni, $hariIni),
            'byDrug' => $this->reports->byDrug($hariIni, $hariIni),
            'byPrescriber' => $this->reports->byPrescriber($hariIni, $hariIni),
            'byUnit' => $this->reports->byUnit($hariIni, $hariIni),
            'byPayer' => $this->reports->byPayer($hariIni, $hariIni),
            'biayaPerTanggal' => $this->reports->biayaPerTanggal($hariIni, $hariIni),
            'top10' => $this->reports->top10($hariIni, $hariIni),
        ];

        $ranap = [
            'byPatient' => $this->reports->byPatient($hariIni, $hariIni, 'ranap'),
            'byDrug' => $this->reports->byDrug($hariIni, $hariIni, 'ranap'),
            'byPrescriber' => $this->reports->byPrescriber($hariIni, $hariIni, 'ranap'),
            'byUnit' => $this->reports->byUnit($hariIni, $hariIni, 'ranap'),
            'byPayer' => $this->reports->byPayer($hariIni, $hariIni, 'ranap'),
            'biayaPerTanggal' => $this->reports->biayaPerTanggal($hariIni, $hariIni, 'ranap'),
            'top10' => $this->reports->top10($hariIni, $hariIni, null, 'ranap'),
        ];

        // Dua resep tanpa penyaring; satu saja setelah disaring ranap.
        $this->assertSame(2, (int) $semua['byPrescriber']->first()->jumlah_resep);
        $this->assertSame(1, (int) $ranap['byPrescriber']->first()->jumlah_resep);
        $this->assertSame(2, (int) $semua['byUnit']->first()->jumlah_resep);
        $this->assertSame(1, (int) $ranap['byUnit']->first()->jumlah_resep);
        $this->assertCount(2, $semua['byPatient']);
        $this->assertCount(1, $ranap['byPatient'], 'byPatient tidak boleh mengabaikan penyaring');
        $this->assertCount(2, $semua['biayaPerTanggal']);
        $this->assertCount(1, $ranap['biayaPerTanggal'], 'biayaPerTanggal tidak boleh mengabaikan penyaring');
        $this->assertSame(1, (int) $ranap['byPayer']->first()->jumlah_resep, 'byPayer tidak boleh mengabaikan penyaring');

        // byDrug & top10 mengelompokkan per obat: dua resep obat sama jadi satu baris,
        // jadi yang dibandingkan jumlah unitnya, bukan jumlah barisnya.
        $this->assertGreaterThan(
            (float) $ranap['byDrug']->first()->jumlah_unit,
            (float) $semua['byDrug']->first()->jumlah_unit,
            'byDrug tidak boleh mengabaikan penyaring'
        );
        $this->assertGreaterThan(
            (float) $ranap['top10']->first()->jumlah_unit,
            (float) $semua['top10']->first()->jumlah_unit,
            'top10 tidak boleh mengabaikan penyaring'
        );
    }
    private function resepDiserahkan(string $careType = 'ralan', string $penjamin = 'UMUM'): Prescription
    {
        $depo = StockLocation::query()->where('code', 'DEPO-RJ')->firstOrFail();
        app(StockLedger::class)->receive($this->obat->id, $depo->id, 'BATCH-LAPORAN-' . uniqid(), 50, now()->addYear()->toDateString(), 1500, $this->apoteker);

        $registrasi = $this->daftarkan(careType: $careType, penjamin: $penjamin);
        $resep = $this->prescriptions->create($registrasi->id, $this->dokter);

        $this->prescriptions->addItem($resep, $this->obat->id, 2, '2x1 tablet');
        $this->prescriptions->submit($resep->refresh());
        $this->prescriptions->review($resep->refresh(), 'disetujui', null, $this->apoteker);

        return $this->prescriptions->dispense($resep->refresh(), $depo->id, $this->apoteker);
    }

    private function daftarkan(string $nama = 'Pasien Laporan Obat', string $careType = 'ralan', string $penjamin = 'UMUM'): Registration
    {
        static $urut = 0;
        $urut++;

        $pasien = app(PatientRegistry::class)->register([
            'name' => $nama . ' ' . $urut, 'sex' => 'L', 'birth_date' => '1990-01-01',
        ]);

        return app(RegistrationService::class)->register(
            patientId: $pasien->id,
            unitId: Unit::query()->where('code', 'POL-UMUM')->value('id'),
            payerId: Payer::query()->where('code', $penjamin)->value('id'),
            extra: $careType === 'ranap' ? ['care_type' => 'ranap'] : [],
        );
    }
}
