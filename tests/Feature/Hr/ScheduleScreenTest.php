<?php

namespace Tests\Feature\Hr;

use App\Modules\Hr\Models\WorkShift;
use App\Modules\Hr\Services\EmployeeService;
use App\Modules\Platform\Database\Seeders\PermissionCatalogSeeder;
use App\Modules\Platform\Database\Seeders\RoleSeeder;
use App\Modules\Platform\Models\Role;
use App\Modules\Platform\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ScheduleScreenTest extends TestCase
{
    use RefreshDatabase;

    private User $adminHr;
    private int $pegawaiId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([PermissionCatalogSeeder::class, RoleSeeder::class]);

        $this->adminHr = User::query()->create([
            'username' => 'uji-jadwal-http', 'name' => 'Admin HR Uji', 'password' => 'password', 'is_active' => true,
        ]);
        $this->adminHr->roles()->attach(Role::query()->where('code', 'admin-hr')->firstOrFail());

        $this->pegawaiId = app(EmployeeService::class)->createEmployee([
            'employee_number' => 'PEG001', 'name' => 'Rudi Hartono', 'position' => 'Perawat',
            'employment_type' => 'tetap', 'hire_date' => '2020-01-01', 'is_active' => true,
        ])->id;
    }

    #[Test]
    public function admin_hr_dapat_menambah_shift_lewat_layar(): void
    {
        $this->actingAs($this->adminHr)
            ->post(route('hr.jadwal.shift.simpan'), [
                'code' => 'PAGI', 'name' => 'Pagi', 'start_time' => '07:00', 'end_time' => '14:00',
            ])
            ->assertRedirect()
            ->assertSessionHas('sukses');

        $this->assertDatabaseHas('hr.work_shifts', ['code' => 'PAGI', 'name' => 'Pagi']);
    }

    #[Test]
    public function admin_hr_dapat_menugaskan_jadwal_lewat_layar(): void
    {
        $shift = WorkShift::query()->create([
            'code' => 'PAGI', 'name' => 'Pagi', 'start_time' => '07:00', 'end_time' => '14:00',
            'tolerance_minutes' => 15, 'is_active' => true,
        ]);

        $this->actingAs($this->adminHr)
            ->post(route('hr.jadwal.simpan'), [
                'employee_id' => $this->pegawaiId, 'work_shift_id' => $shift->id,
                'schedule_date' => now()->toDateString(),
            ])
            ->assertRedirect()
            ->assertSessionHas('sukses');

        $this->assertDatabaseHas('hr.duty_schedules', [
            'employee_id' => $this->pegawaiId, 'work_shift_id' => $shift->id,
        ]);
    }

    #[Test]
    public function peran_lain_ditolak_mengakses_layar_jadwal(): void
    {
        $petugasDaftar = User::query()->create([
            'username' => 'uji-daftar-jadwal', 'name' => 'Petugas Daftar', 'password' => 'password', 'is_active' => true,
        ]);
        $petugasDaftar->roles()->attach(Role::query()->where('code', 'petugas-daftar')->firstOrFail());

        $this->actingAs($petugasDaftar)
            ->get(route('hr.jadwal.index'))
            ->assertForbidden();
    }
}
