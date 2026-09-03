<?php

namespace Tests\Feature\Encounter;

use App\Modules\Catalog\Models\Payer;
use App\Modules\Encounter\Models\OperationBooking;
use App\Modules\Encounter\Models\Registration;
use App\Modules\Encounter\Services\OperationBookingService;
use App\Modules\Encounter\Services\RegistrationException;
use App\Modules\Encounter\Services\RegistrationService;
use App\Modules\Identity\Services\PatientRegistry;
use App\Modules\Organization\Models\Unit;
use App\Modules\Platform\Database\Seeders\PermissionCatalogSeeder;
use App\Modules\Platform\Database\Seeders\RoleSeeder;
use App\Modules\Platform\Models\Role;
use App\Modules\Platform\Models\User;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class OperationBookingTest extends TestCase
{
    use RefreshDatabase;

    private OperationBookingService $bookings;
    private User $petugasDaftar;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([PermissionCatalogSeeder::class, RoleSeeder::class, ReferenceDataSeeder::class]);

        $this->bookings = app(OperationBookingService::class);

        $this->petugasDaftar = User::query()->create([
            'username' => 'uji-booking-op', 'name' => 'Petugas Daftar Uji', 'password' => 'password', 'is_active' => true,
        ]);
        $this->petugasDaftar->roles()->attach(Role::query()->where('code', 'petugas-daftar')->firstOrFail());
    }

    #[Test]
    public function jadwal_operasi_tersimpan_dan_mewarisi_konteks_kunjungan(): void
    {
        $registrasi = $this->daftarkan();

        $booking = $this->bookings->book($registrasi->id, [
            'procedure_name' => 'Appendektomi',
            'operating_room' => 'OK 1',
            'scheduled_at' => now()->addDays(2),
        ], $this->petugasDaftar);

        $this->assertStringStartsWith('OPR-' . now()->format('Ymd'), $booking->booking_number);
        $this->assertSame($registrasi->patient_id, $booking->patient_id);
        $this->assertSame($registrasi->patient_mrn, $booking->patient_mrn);
        $this->assertSame(OperationBooking::STATUS_DIJADWALKAN, $booking->status);
    }

    #[Test]
    public function kunjungan_yang_tidak_ada_ditolak(): void
    {
        $this->expectException(RegistrationException::class);
        $this->expectExceptionMessage('tidak ditemukan');

        $this->bookings->book(999999, [
            'procedure_name' => 'Appendektomi', 'scheduled_at' => now()->addDay(),
        ], $this->petugasDaftar);
    }

    #[Test]
    public function jadwal_bisa_ditandai_selesai(): void
    {
        $registrasi = $this->daftarkan();
        $booking = $this->bookings->book($registrasi->id, [
            'procedure_name' => 'Appendektomi', 'scheduled_at' => now()->addDay(),
        ], $this->petugasDaftar);

        $selesai = $this->bookings->complete($booking);

        $this->assertSame(OperationBooking::STATUS_SELESAI, $selesai->status);
    }

    #[Test]
    public function jadwal_yang_sudah_dibatalkan_tidak_bisa_diubah_lagi(): void
    {
        $registrasi = $this->daftarkan();
        $booking = $this->bookings->book($registrasi->id, [
            'procedure_name' => 'Appendektomi', 'scheduled_at' => now()->addDay(),
        ], $this->petugasDaftar);
        $this->bookings->cancel($booking);

        $this->expectException(RegistrationException::class);
        $this->expectExceptionMessage('sudah Dibatalkan');

        $this->bookings->complete($booking->refresh());
    }

    // ------------------------------------------------------------------ bantu

    private function daftarkan(): Registration
    {
        static $urut = 0;
        $urut++;

        $pasien = app(PatientRegistry::class)->register([
            'name' => 'Pasien Operasi ' . $urut, 'sex' => 'L', 'birth_date' => '1990-01-01',
        ]);

        return app(RegistrationService::class)->register(
            patientId: $pasien->id,
            unitId: Unit::query()->where('code', 'POL-UMUM')->value('id'),
            payerId: Payer::query()->where('code', 'UMUM')->value('id'),
        );
    }
}
