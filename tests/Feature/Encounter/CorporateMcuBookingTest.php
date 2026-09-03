<?php

namespace Tests\Feature\Encounter;

use App\Modules\Encounter\Models\CorporateMcuBooking;
use App\Modules\Encounter\Services\CorporateMcuBookingService;
use App\Modules\Encounter\Services\RegistrationException;
use App\Modules\Platform\Database\Seeders\PermissionCatalogSeeder;
use App\Modules\Platform\Database\Seeders\RoleSeeder;
use App\Modules\Platform\Models\Role;
use App\Modules\Platform\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class CorporateMcuBookingTest extends TestCase
{
    use RefreshDatabase;

    private CorporateMcuBookingService $bookings;
    private User $petugasDaftar;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([PermissionCatalogSeeder::class, RoleSeeder::class]);

        $this->bookings = app(CorporateMcuBookingService::class);

        $this->petugasDaftar = User::query()->create([
            'username' => 'uji-mcu', 'name' => 'Petugas Daftar Uji', 'password' => 'password', 'is_active' => true,
        ]);
        $this->petugasDaftar->roles()->attach(Role::query()->where('code', 'petugas-daftar')->firstOrFail());
    }

    #[Test]
    public function booking_tersimpan_dan_bernomor_prefix_mcu(): void
    {
        $booking = $this->bookings->book([
            'company_name' => 'PT Maju Jaya',
            'scheduled_date' => now()->addDays(3)->toDateString(),
            'employee_count' => 25,
        ], $this->petugasDaftar);

        $this->assertStringStartsWith('MCU-' . now()->format('Ymd'), $booking->booking_number);
        $this->assertSame(CorporateMcuBooking::STATUS_DIJADWALKAN, $booking->status);
        $this->assertSame($this->petugasDaftar->id, $booking->booked_by);
    }

    #[Test]
    public function jumlah_karyawan_nol_ditolak(): void
    {
        $this->expectException(RegistrationException::class);
        $this->expectExceptionMessage('lebih dari nol');

        $this->bookings->book([
            'company_name' => 'PT Tanpa Karyawan',
            'scheduled_date' => now()->addDay()->toDateString(),
            'employee_count' => 0,
        ], $this->petugasDaftar);
    }

    #[Test]
    public function booking_bisa_ditandai_selesai(): void
    {
        $booking = $this->bookings->book([
            'company_name' => 'PT Selesai', 'scheduled_date' => now()->toDateString(), 'employee_count' => 5,
        ], $this->petugasDaftar);

        $selesai = $this->bookings->complete($booking);

        $this->assertSame(CorporateMcuBooking::STATUS_SELESAI, $selesai->status);
    }

    #[Test]
    public function booking_yang_sudah_dibatalkan_tidak_bisa_diubah_lagi(): void
    {
        $booking = $this->bookings->book([
            'company_name' => 'PT Batal', 'scheduled_date' => now()->toDateString(), 'employee_count' => 5,
        ], $this->petugasDaftar);
        $this->bookings->cancel($booking);

        $this->expectException(RegistrationException::class);
        $this->expectExceptionMessage('sudah Dibatalkan');

        $this->bookings->complete($booking->refresh());
    }
}
