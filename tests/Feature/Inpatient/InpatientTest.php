<?php

namespace Tests\Feature\Inpatient;

use App\Modules\Catalog\Models\Payer;
use App\Modules\Encounter\Models\Registration;
use App\Modules\Encounter\Services\RegistrationService;
use App\Modules\Identity\Services\PatientRegistry;
use App\Modules\Inpatient\Models\Admission;
use App\Modules\Inpatient\Models\Bed;
use App\Modules\Inpatient\Models\DietOrder;
use App\Modules\Inpatient\Models\Room;
use App\Modules\Inpatient\Services\AdmissionService;
use App\Modules\Inpatient\Services\DietOrderService;
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
    private DietOrderService $dietOrders;
    private RegistrationService $registrations;
    private User $petugasRanap;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([PermissionCatalogSeeder::class, RoleSeeder::class, ReferenceDataSeeder::class]);

        $this->rooms = app(RoomService::class);
        $this->admissions = app(AdmissionService::class);
        $this->dietOrders = app(DietOrderService::class);
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
    public function order_diet_baru_menutup_yang_lama(): void
    {
        $bed = $this->buatBed();
        $admisi = $this->admissions->admit($this->daftarkanRanap('Budi Santoso')->id, $bed);

        $ordersLama = $this->dietOrders->order($admisi, ['diet_type' => 'biasa', 'start_date' => '2026-09-01'], $this->petugasRanap);
        $ordersBaru = $this->dietOrders->order($admisi->fresh(), ['diet_type' => 'rendah-garam', 'start_date' => '2026-09-05'], $this->petugasRanap);

        $this->assertSame(DietOrder::STATUS_DIHENTIKAN, $ordersLama->fresh()->status);
        $this->assertSame('2026-09-05', $ordersLama->fresh()->end_date->toDateString());
        $this->assertSame(DietOrder::STATUS_AKTIF, $ordersBaru->status);
        $this->assertSame($ordersBaru->id, $admisi->fresh()->activeDietOrder->id);
    }

    #[Test]
    public function order_diet_bisa_dihentikan_tanpa_pengganti(): void
    {
        $bed = $this->buatBed();
        $admisi = $this->admissions->admit($this->daftarkanRanap('Budi Santoso')->id, $bed);
        $order = $this->dietOrders->order($admisi, ['diet_type' => 'biasa', 'start_date' => now()->toDateString()], $this->petugasRanap);

        $this->dietOrders->stop($order);

        $this->assertSame(DietOrder::STATUS_DIHENTIKAN, $order->fresh()->status);
        $this->assertNull($admisi->fresh()->activeDietOrder);
    }

    #[Test]
    public function order_diet_yang_sudah_dihentikan_tidak_bisa_dihentikan_ulang(): void
    {
        $bed = $this->buatBed();
        $admisi = $this->admissions->admit($this->daftarkanRanap('Budi Santoso')->id, $bed);
        $order = $this->dietOrders->order($admisi, ['diet_type' => 'biasa', 'start_date' => now()->toDateString()], $this->petugasRanap);
        $this->dietOrders->stop($order);

        $this->expectException(InpatientException::class);
        $this->dietOrders->stop($order->fresh());
    }

    #[Test]
    public function tidak_bisa_order_diet_untuk_admisi_yang_sudah_pulang(): void
    {
        $bed = $this->buatBed();
        $admisi = $this->admissions->admit($this->daftarkanRanap('Budi Santoso')->id, $bed);
        $this->admissions->discharge($admisi, 'sembuh', null);

        $this->expectException(InpatientException::class);
        $this->dietOrders->order($admisi->fresh(), ['diet_type' => 'biasa', 'start_date' => now()->toDateString()], $this->petugasRanap);
    }

    #[Test]
    public function pemulangan_otomatis_menghentikan_order_diet_yang_masih_aktif(): void
    {
        $bed = $this->buatBed();
        $admisi = $this->admissions->admit($this->daftarkanRanap('Budi Santoso')->id, $bed);
        $order = $this->dietOrders->order($admisi, ['diet_type' => 'biasa', 'start_date' => now()->toDateString()], $this->petugasRanap);

        $this->admissions->discharge($admisi->fresh(), 'sembuh', null);

        $this->assertSame(DietOrder::STATUS_DIHENTIKAN, $order->fresh()->status);
    }

    #[Test]
    public function layar_kelola_kamar_dan_aksi_admisi_hanya_untuk_pemegang_tindakan_ranap(): void
    {
        $this->actingAs($this->petugasRanap)->get(route('inpatient.index'))->assertOk();
        $this->actingAs($this->petugasRanap)->get(route('inpatient.kamar.index'))->assertOk();

        $tanpaAkses = User::query()->create([
            'username' => 'uji-admin-hr-ranap', 'name' => 'Admin HR Uji', 'password' => 'password', 'is_active' => true,
        ]);
        $tanpaAkses->roles()->attach(Role::query()->where('code', 'admin-hr')->firstOrFail());

        $this->actingAs($tanpaAkses)->get(route('inpatient.index'))->assertForbidden();
        $this->actingAs($tanpaAkses)->get(route('inpatient.kamar.index'))->assertForbidden();
    }

    #[Test]
    public function dokter_bisa_melihat_daftar_dirawat_untuk_diet_tapi_tidak_kelola_kamar(): void
    {
        $dokter = User::query()->create([
            'username' => 'uji-dokter-ranap', 'name' => 'Dokter Uji', 'password' => 'password', 'is_active' => true,
        ]);
        $dokter->roles()->attach(Role::query()->where('code', 'dokter')->firstOrFail());

        // dokter punya diet_pasien (wholesale context encounter, tidak
        // dikecualikan) tapi tidak tindakan_ranap (dikecualikan) — jadi bisa
        // buka /rawat-inap untuk mencatat diet, tapi tidak bisa kelola kamar.
        $this->actingAs($dokter)->get(route('inpatient.index'))->assertOk();
        $this->actingAs($dokter)->get(route('inpatient.kamar.index'))->assertForbidden();

        $bed = $this->buatBed();
        $admisi = $this->admissions->admit($this->daftarkanRanap('Budi Santoso')->id, $bed);

        $this->actingAs($dokter)
            ->post(route('inpatient.admisi.diet.simpan', $admisi), [
                'diet_type' => 'rendah-garam',
                'start_date' => now()->toDateString(),
            ])
            ->assertRedirect()
            ->assertSessionHas('sukses');

        $this->assertDatabaseHas('inpatient.diet_orders', ['admission_id' => $admisi->id, 'diet_type' => 'rendah-garam']);

        // Tapi tidak bisa mengadmisi atau mengelola kamar.
        $this->actingAs($dokter)
            ->post(route('inpatient.admisi.simpan'), ['registration_id' => 1, 'bed_id' => $bed->id])
            ->assertForbidden();
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
