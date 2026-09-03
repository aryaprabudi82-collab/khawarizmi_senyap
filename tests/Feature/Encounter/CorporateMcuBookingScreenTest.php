<?php

namespace Tests\Feature\Encounter;

use App\Modules\Encounter\Models\CorporateMcuBooking;
use App\Modules\Platform\Database\Seeders\PermissionCatalogSeeder;
use App\Modules\Platform\Database\Seeders\RoleSeeder;
use App\Modules\Platform\Models\Role;
use App\Modules\Platform\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class CorporateMcuBookingScreenTest extends TestCase
{
    use RefreshDatabase;

    private User $petugasDaftar;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([PermissionCatalogSeeder::class, RoleSeeder::class]);

        $this->petugasDaftar = User::query()->create([
            'username' => 'uji-mcu-http', 'name' => 'Petugas Daftar Uji', 'password' => 'password', 'is_active' => true,
        ]);
        $this->petugasDaftar->roles()->attach(Role::query()->where('code', 'petugas-daftar')->firstOrFail());
    }

    #[Test]
    public function petugas_daftar_dapat_membuat_booking_lewat_layar(): void
    {
        $this->actingAs($this->petugasDaftar)
            ->post(route('mcu-perusahaan.simpan'), [
                'company_name' => 'PT Sejahtera Abadi',
                'scheduled_date' => now()->addDays(2)->toDateString(),
                'employee_count' => 15,
                'package_description' => 'MCU standar',
            ])
            ->assertRedirect(route('mcu-perusahaan.index'))
            ->assertSessionHas('sukses');

        $this->assertDatabaseHas('encounter.corporate_mcu_bookings', [
            'company_name' => 'PT Sejahtera Abadi', 'employee_count' => 15,
        ]);
    }

    #[Test]
    public function daftar_menampilkan_booking_yang_tersimpan(): void
    {
        $booking = CorporateMcuBooking::query()->create([
            'booking_number' => 'MCU-TEST-00001', 'company_name' => 'PT Tampil',
            'scheduled_date' => now()->toDateString(), 'employee_count' => 10,
            'status' => CorporateMcuBooking::STATUS_DIJADWALKAN, 'booked_at' => now(),
        ]);

        $this->actingAs($this->petugasDaftar)
            ->get(route('mcu-perusahaan.index'))
            ->assertOk()
            ->assertSee('PT Tampil')
            ->assertSee($booking->booking_number);
    }

    #[Test]
    public function dokter_ditolak_mengakses_layar_booking_mcu(): void
    {
        $dokter = User::query()->create([
            'username' => 'uji-dokter-mcu', 'name' => 'Dokter Uji', 'password' => 'password', 'is_active' => true,
        ]);
        $dokter->roles()->attach(Role::query()->where('code', 'dokter')->firstOrFail());

        $this->actingAs($dokter)
            ->get(route('mcu-perusahaan.index'))
            ->assertForbidden();
    }
}
