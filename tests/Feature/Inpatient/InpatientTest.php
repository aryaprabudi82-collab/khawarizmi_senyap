<?php

namespace Tests\Feature\Inpatient;

use App\Modules\Catalog\Models\Payer;
use App\Modules\Encounter\Models\Registration;
use App\Modules\Encounter\Services\RegistrationService;
use App\Modules\Identity\Services\PatientRegistry;
use App\Modules\Inpatient\Models\Admission;
use App\Modules\Inpatient\Models\Bed;
use App\Modules\Inpatient\Models\Room;
use App\Modules\Inpatient\Services\AdmissionService;
use App\Modules\Inpatient\Services\InpatientException;
use App\Modules\Inpatient\Services\RoomService;
use App\Modules\Organization\Models\Unit;
use App\Modules\Platform\Database\Seeders\PermissionCatalogSeeder;
use App\Modules\Platform\Database\Seeders\RoleSeeder;
use App\Modules\Platform\Models\Role;
use App\Modules\Platform\Models\User;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class InpatientTest extends TestCase
{
    use RefreshDatabase;

    private RoomService $rooms;
    private AdmissionService $admissions;
    private RegistrationService $registrations;
    private User $petugasRanap;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([PermissionCatalogSeeder::class, RoleSeeder::class, ReferenceDataSeeder::class]);

        $this->rooms = app(RoomService::class);
        $this->admissions = app(AdmissionService::class);
        $this->registrations = app(RegistrationService::class);

        $this->petugasRanap = User::query()->create([
            'username' => 'uji-petugas-ranap', 'name' => 'Petugas Ranap Uji', 'password' => 'password', 'is_active' => true,
        ]);
        $this->petugasRanap->roles()->attach(Role::query()->where('code', 'petugas-ranap')->firstOrFail());
    }

    #[Test]
    public function bed_baru_berstatus_tersedia(): void
    {
        $bed = $this->buatBed();

        $this->assertSame(Bed::STATUS_TERSEDIA, $bed->status);
    }

    #[Test]
    public function admisi_mengisi_bed_dan_mengunci_pasangan_registrasi_bed(): void
    {
        $bed = $this->buatBed();
        $registrasi = $this->daftarkanRanap('Budi Santoso');

        $admisi = $this->admissions->admit($registrasi->id, $bed);

        $this->assertMatchesRegularExpression('/^RANAP-\d{4}-\d{5}$/', $admisi->admission_number);
        $this->assertSame(Admission::STATUS_DIRAWAT, $admisi->status);
        $this->assertSame(Bed::STATUS_TERISI, $bed->fresh()->status);
        $this->assertSame('Budi Santoso', $admisi->patient_name);
    }

    #[Test]
    public function tidak_bisa_admisi_ke_bed_yang_sudah_terisi(): void
    {
        $bed = $this->buatBed();
        $this->admissions->admit($this->daftarkanRanap('Pasien Pertama')->id, $bed);

        $this->expectException(InpatientException::class);
        $this->admissions->admit($this->daftarkanRanap('Pasien Kedua')->id, $bed);
    }

    #[Test]
    public function tidak_bisa_admisi_registrasi_yang_bukan_ranap(): void
    {
        $bed = $this->buatBed();
        $registrasiRalan = $this->registrations->register(
            patientId: app(PatientRegistry::class)->register(['name' => 'Pasien Ralan', 'sex' => 'L', 'birth_date' => '1990-01-01'])->id,
            unitId: Unit::query()->where('code', 'POL-UMUM')->firstOrFail()->id,
            payerId: Payer::query()->where('code', 'UMUM')->value('id'),
        );

        $this->expectException(InpatientException::class);
        $this->admissions->admit($registrasiRalan->id, $bed);
    }

    #[Test]
    public function registrasi_yang_sama_tidak_bisa_diadmisi_dua_kali(): void
    {
        $bed1 = $this->buatBed();
        $bed2 = $this->buatBed('K002', 'B01');
        $registrasi = $this->daftarkanRanap('Budi Santoso');

        $this->admissions->admit($registrasi->id, $bed1);

        $this->expectException(InpatientException::class);
        $this->admissions->admit($registrasi->id, $bed2);
    }

    #[Test]
    public function pemulangan_pasien_membuka_status_dibersihkan_bukan_langsung_tersedia(): void
    {
        $bed = $this->buatBed();
        $admisi = $this->admissions->admit($this->daftarkanRanap('Budi Santoso')->id, $bed);

        $dipulangkan = $this->admissions->discharge($admisi, 'sembuh', 'Kondisi membaik.');

        $this->assertSame(Admission::STATUS_PULANG, $dipulangkan->status);
        $this->assertNotNull($dipulangkan->discharged_at);
        $this->assertSame(Bed::STATUS_DIBERSIHKAN, $bed->fresh()->status);
    }

    #[Test]
    public function bed_yang_dibersihkan_harus_ditandai_bersih_dulu_sebelum_tersedia_lagi(): void
    {
        $bed = $this->buatBed();
        $admisi = $this->admissions->admit($this->daftarkanRanap('Budi Santoso')->id, $bed);
        $this->admissions->discharge($admisi, 'sembuh', null);

        $this->rooms->markClean($bed->fresh());

        $this->assertSame(Bed::STATUS_TERSEDIA, $bed->fresh()->status);
    }

    #[Test]
    public function bed_terisi_tidak_bisa_langsung_ditandai_bersih(): void
    {
        $bed = $this->buatBed();
        $this->admissions->admit($this->daftarkanRanap('Budi Santoso')->id, $bed);

        $this->expectException(InpatientException::class);
        $this->rooms->markClean($bed);
    }

    #[Test]
    public function pasien_yang_sudah_pulang_tidak_bisa_dipulangkan_ulang(): void
    {
        $bed = $this->buatBed();
        $admisi = $this->admissions->admit($this->daftarkanRanap('Budi Santoso')->id, $bed);
        $this->admissions->discharge($admisi, 'sembuh', null);

        $this->expectException(InpatientException::class);
        $this->admissions->discharge($admisi->fresh(), 'sembuh', null);
    }

    #[Test]
    public function bed_terisi_tidak_bisa_dinonaktifkan(): void
    {
        $bed = $this->buatBed();
        $this->admissions->admit($this->daftarkanRanap('Budi Santoso')->id, $bed);

        $this->expectException(InpatientException::class);
        $this->rooms->deactivate($bed);
    }

    #[Test]
    public function layar_rawat_inap_hanya_untuk_petugas_ranap(): void
    {
        $this->actingAs($this->petugasRanap)->get(route('inpatient.index'))->assertOk();
        $this->actingAs($this->petugasRanap)->get(route('inpatient.kamar.index'))->assertOk();

        $dokter = User::query()->create([
            'username' => 'uji-dokter-ranap', 'name' => 'Dokter Uji', 'password' => 'password', 'is_active' => true,
        ]);
        $dokter->roles()->attach(Role::query()->where('code', 'dokter')->firstOrFail());

        $this->actingAs($dokter)->get(route('inpatient.index'))->assertForbidden();
    }

    #[Test]
    public function admisi_lewat_http_menampilkan_registrasi_yang_menunggu_kamar(): void
    {
        $bed = $this->buatBed();
        $registrasi = $this->daftarkanRanap('Budi Santoso');

        $this->actingAs($this->petugasRanap)
            ->get(route('inpatient.index'))
            ->assertOk()
            ->assertSee('Budi Santoso')
            ->assertSee($registrasi->registration_number);

        $this->actingAs($this->petugasRanap)
            ->post(route('inpatient.admisi.simpan'), [
                'registration_id' => $registrasi->id,
                'bed_id' => $bed->id,
            ])
            ->assertRedirect()
            ->assertSessionHas('sukses');

        $this->assertDatabaseHas('inpatient.admissions', [
            'registration_id' => $registrasi->id,
            'bed_id' => $bed->id,
            'status' => 'dirawat',
        ]);
    }

    // ------------------------------------------------------------------ bantu

    private function buatBed(string $roomNumber = 'K001', string $bedNumber = 'B01'): Bed
    {
        $room = Room::query()->where('room_number', $roomNumber)->first()
            ?? $this->rooms->createRoom(['room_number' => $roomNumber, 'room_class' => 'kelas-3']);

        return $this->rooms->addBed($room, $bedNumber);
    }

    private function daftarkanRanap(string $nama): Registration
    {
        $pasien = app(PatientRegistry::class)->register(['name' => $nama, 'sex' => 'L', 'birth_date' => '1990-01-01']);

        return $this->registrations->register(
            patientId: $pasien->id,
            unitId: Unit::query()->where('code', 'POL-UMUM')->firstOrFail()->id,
            payerId: Payer::query()->where('code', 'UMUM')->value('id'),
            extra: ['care_type' => 'ranap'],
        );
    }
}
