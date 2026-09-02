<?php

namespace Tests\Feature\Pharmacy;

use App\Modules\Catalog\Models\Payer;
use App\Modules\Clinical\Database\Seeders\DiagnosisCodeSeeder;
use App\Modules\Clinical\Services\ClinicalRecordService;
use App\Modules\Encounter\Models\Registration;
use App\Modules\Encounter\Services\RegistrationService;
use App\Modules\Identity\Services\PatientRegistry;
use App\Modules\Organization\Models\Unit;
use App\Modules\Pharmacy\Database\Seeders\PharmacySeeder;
use App\Modules\Pharmacy\Models\Drug;
use App\Modules\Pharmacy\Models\Prescription;
use App\Modules\Pharmacy\Models\StockLocation;
use App\Modules\Pharmacy\Services\PrescriptionService;
use App\Modules\Platform\Database\Seeders\PermissionCatalogSeeder;
use App\Modules\Platform\Database\Seeders\RoleSeeder;
use App\Modules\Platform\Models\Role;
use App\Modules\Platform\Models\User;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class PrescriptionScreenTest extends TestCase
{
    use RefreshDatabase;

    private User $dokter;
    private User $apoteker;
    private StockLocation $depo;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([
            PermissionCatalogSeeder::class,
            RoleSeeder::class,
            ReferenceDataSeeder::class,
            DiagnosisCodeSeeder::class,
            PharmacySeeder::class,
        ]);

        $this->dokter = $this->buatPengguna('dokter');
        $this->apoteker = $this->buatPengguna('apoteker');
        $this->depo = StockLocation::query()->where('code', 'DEPO-RJ')->firstOrFail();
    }

    #[Test]
    public function dokter_dapat_membuka_resep_dari_kunjungan(): void
    {
        $registrasi = $this->daftarkan('Wahyu Ramadhan');

        $respons = $this->actingAs($this->dokter)->post(route('resep.buat', $registrasi->id));

        $respons->assertRedirect();
        $this->assertDatabaseHas('pharmacy.prescriptions', [
            'registration_id' => $registrasi->id,
            'status' => Prescription::STATUS_DITULIS,
        ]);
    }

    #[Test]
    public function petugas_pendaftaran_tidak_bisa_menulis_resep(): void
    {
        $registrasi = $this->daftarkan();
        $petugas = $this->buatPengguna('petugas-daftar');

        $this->actingAs($petugas)
            ->post(route('resep.buat', $registrasi->id))
            ->assertForbidden();
    }

    #[Test]
    public function dokter_dapat_menambah_item_dan_mengirim_resep(): void
    {
        $resep = $this->tulisResep();
        $obat = Drug::query()->where('code', 'OBT-002')->firstOrFail();

        $this->actingAs($this->dokter)
            ->post(route('resep.item.simpan', $resep), [
                'drug_id' => $obat->id,
                'quantity' => 10,
                'dosage_instruction' => '3x1 sesudah makan',
            ])
            ->assertRedirect()
            ->assertSessionHas('sukses');

        $this->actingAs($this->dokter)
            ->post(route('resep.kirim', $resep))
            ->assertRedirect()
            ->assertSessionHas('sukses');

        $this->assertSame(Prescription::STATUS_MENUNGGU_TELAAH, $resep->fresh()->status);
    }

    #[Test]
    public function apoteker_melihat_peringatan_alergi_di_layar_telaah(): void
    {
        $registrasi = $this->daftarkan('Citra Permata');
        app(ClinicalRecordService::class)->recordAllergy($registrasi->patient_id, 'Amoksisilin', ['severity' => 'berat']);

        $resep = app(PrescriptionService::class)->create($registrasi->id, $this->dokter);
        app(PrescriptionService::class)->addItem(
            $resep, Drug::query()->where('code', 'OBT-001')->value('id'), 10, '3x1'
        );
        app(PrescriptionService::class)->submit($resep->refresh());

        $this->actingAs($this->apoteker)
            ->get(route('resep.show', $resep))
            ->assertOk()
            ->assertSee('Peringatan alergi berat')
            ->assertSee('Amoksisilin');
    }

    #[Test]
    public function apoteker_dapat_menyetujui_resep_tanpa_temuan(): void
    {
        $resep = $this->resepMenunggu();

        $this->actingAs($this->apoteker)
            ->post(route('resep.telaah', $resep), ['outcome' => 'disetujui'])
            ->assertRedirect()
            ->assertSessionHas('sukses');

        $this->assertSame(Prescription::STATUS_DISETUJUI, $resep->fresh()->status);
    }

    #[Test]
    public function dokter_tidak_bisa_menelaah_resepnya_sendiri(): void
    {
        $resep = $this->resepMenunggu();

        $this->actingAs($this->dokter)
            ->post(route('resep.telaah', $resep), ['outcome' => 'disetujui'])
            ->assertForbidden();
    }

    #[Test]
    public function apoteker_dapat_menyerahkan_resep_yang_disetujui_dan_stok_berkurang(): void
    {
        $resep = $this->resepMenunggu();
        $obat = Drug::query()->where('code', 'OBT-002')->firstOrFail();
        $stokSebelum = app(\App\Modules\Pharmacy\Services\StockLedger::class)
            ->availableQuantity($obat->id, $this->depo->id);

        $this->actingAs($this->apoteker)->post(route('resep.telaah', $resep), ['outcome' => 'disetujui']);

        $this->actingAs($this->apoteker)
            ->post(route('resep.serah', $resep->fresh()), ['location_id' => $this->depo->id])
            ->assertRedirect()
            ->assertSessionHas('sukses');

        $resep->refresh();
        $this->assertSame(Prescription::STATUS_DISERAHKAN, $resep->status);

        $stokSesudah = app(\App\Modules\Pharmacy\Services\StockLedger::class)
            ->availableQuantity($obat->id, $this->depo->id);
        $this->assertSame($stokSebelum - 10, $stokSesudah);
    }

    #[Test]
    public function penyerahan_tanpa_persetujuan_ditolak_dari_layar(): void
    {
        $resep = $this->resepMenunggu();

        $this->actingAs($this->apoteker)
            ->post(route('resep.serah', $resep), ['location_id' => $this->depo->id])
            ->assertRedirect()
            ->assertSessionHas('galat');

        $this->assertSame(Prescription::STATUS_MENUNGGU_TELAAH, $resep->fresh()->status);
    }

    #[Test]
    public function antrean_farmasi_menampilkan_resep_dan_ringkasan_status(): void
    {
        $this->resepMenunggu();

        $this->actingAs($this->apoteker)
            ->get(route('resep.index'))
            ->assertOk()
            ->assertSee('Antrean Resep')
            ->assertSee('Menunggu telaah');
    }

    #[Test]
    public function pencarian_obat_mengembalikan_json(): void
    {
        $this->actingAs($this->dokter)
            ->get(route('resep.cari-obat', ['q' => 'Amoksisilin']))
            ->assertOk()
            ->assertJsonFragment(['unit' => 'kapsul']);
    }

    // ------------------------------------------------------------------ bantu

    private function buatPengguna(string $kodePeran): User
    {
        $user = User::query()->create([
            'username' => 'uji-farmasi-http-' . $kodePeran,
            'name' => 'Pengguna ' . $kodePeran,
            'password' => 'password',
            'is_active' => true,
        ]);

        $user->roles()->attach(Role::query()->where('code', $kodePeran)->firstOrFail());

        return $user->fresh(['roles']);
    }

    private function daftarkan(string $nama = 'Pasien Uji'): Registration
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

    private function tulisResep(): Prescription
    {
        return app(PrescriptionService::class)->create($this->daftarkan()->id, $this->dokter);
    }

    private function resepMenunggu(): Prescription
    {
        $resep = $this->tulisResep();

        app(PrescriptionService::class)->addItem(
            $resep, Drug::query()->where('code', 'OBT-002')->value('id'), 10, '3x1 sesudah makan'
        );

        return app(PrescriptionService::class)->submit($resep->refresh());
    }
}
