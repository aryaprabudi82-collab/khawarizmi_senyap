<?php

namespace Tests\Feature\Hr;

use App\Modules\Hr\Models\DutySchedule;
use App\Modules\Hr\Models\Employee;
use App\Modules\Hr\Models\WorkShift;
use App\Modules\Hr\Services\AttendanceService;
use App\Modules\Hr\Services\EmployeeService;
use App\Modules\Hr\Services\HrException;
use App\Modules\Hr\Services\ScheduleService;
use App\Modules\Platform\Database\Seeders\PermissionCatalogSeeder;
use App\Modules\Platform\Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ScheduleTest extends TestCase
{
    use RefreshDatabase;

    private ScheduleService $schedules;
    private AttendanceService $attendance;
    private EmployeeService $employees;
    private Employee $pegawai;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([PermissionCatalogSeeder::class, RoleSeeder::class]);

        $this->schedules = app(ScheduleService::class);
        $this->attendance = app(AttendanceService::class);
        $this->employees = app(EmployeeService::class);

        $this->pegawai = $this->employees->createEmployee([
            'employee_number' => 'PEG001', 'name' => 'Rudi Hartono', 'position' => 'Perawat',
            'employment_type' => 'tetap', 'hire_date' => '2020-01-01', 'is_active' => true,
        ]);
    }

    #[Test]
    public function shift_tersimpan(): void
    {
        $shift = $this->schedules->createShift([
            'code' => 'PAGI', 'name' => 'Pagi', 'start_time' => '07:00', 'end_time' => '14:00',
            'tolerance_minutes' => 15, 'is_active' => true,
        ]);

        $this->assertSame('Pagi', $shift->name);
        $this->assertSame(15, $shift->tolerance_minutes);
    }

    #[Test]
    public function jadwal_ditugaskan_dan_bisa_dibatalkan(): void
    {
        $shift = $this->schedules->createShift([
            'code' => 'PAGI', 'name' => 'Pagi', 'start_time' => '07:00', 'end_time' => '14:00', 'is_active' => true,
        ]);

        $jadwal = $this->schedules->assignDuty($this->pegawai->id, $shift->id, now()->toDateString(), null, null, null);
        $this->assertSame(DutySchedule::STATUS_TERJADWAL, $jadwal->status);

        $dibatalkan = $this->schedules->cancelDuty($jadwal);
        $this->assertSame(DutySchedule::STATUS_DIBATALKAN, $dibatalkan->status);
    }

    #[Test]
    public function jadwal_yang_sudah_dibatalkan_tidak_bisa_dibatalkan_ulang(): void
    {
        $shift = $this->schedules->createShift([
            'code' => 'PAGI', 'name' => 'Pagi', 'start_time' => '07:00', 'end_time' => '14:00', 'is_active' => true,
        ]);
        $jadwal = $this->schedules->assignDuty($this->pegawai->id, $shift->id, now()->toDateString(), null, null, null);
        $this->schedules->cancelDuty($jadwal);

        $this->expectException(HrException::class);
        $this->expectExceptionMessage('sudah dibatalkan');

        $this->schedules->cancelDuty($jadwal->refresh());
    }

    #[Test]
    public function checkin_tanpa_jadwal_tidak_pernah_ditandai_terlambat(): void
    {
        $hasil = $this->attendance->checkIn($this->pegawai->id);

        $this->assertFalse($hasil->is_late);
    }

    #[Test]
    public function checkin_dalam_toleransi_tidak_ditandai_terlambat(): void
    {
        // Shift dimulai jauh di masa lalu hari ini supaya toleransi 999
        // menit menutup jam berapa pun testnya jalan — cara sederhana
        // menghindari flaky test dengan waktu absolut.
        $shift = $this->schedules->createShift([
            'code' => 'PAGI', 'name' => 'Pagi', 'start_time' => '00:00', 'end_time' => '23:59',
            'tolerance_minutes' => 1439, 'is_active' => true,
        ]);
        $this->schedules->assignDuty($this->pegawai->id, $shift->id, now()->toDateString(), null, null, null);

        $hasil = $this->attendance->checkIn($this->pegawai->id);

        $this->assertFalse($hasil->is_late);
        $this->assertNotNull($hasil->duty_schedule_id);
    }

    #[Test]
    public function checkin_melewati_toleransi_ditandai_terlambat(): void
    {
        // Shift "berakhir" 1 menit dari sekarang dengan toleransi nol,
        // dijamin checkin lewat dari batas kapan pun test ini jalan.
        $batas = now()->addMinute()->format('H:i');
        $shift = $this->schedules->createShift([
            'code' => 'MLM', 'name' => 'Malam', 'start_time' => '00:00', 'end_time' => '23:59',
            'tolerance_minutes' => 0, 'is_active' => true,
        ]);
        // start_time di masa lalu (00:00) + toleransi 0 -> batas jam 00:00,
        // checkin kapan pun hari ini pasti sudah lewat itu.
        $this->schedules->assignDuty($this->pegawai->id, $shift->id, now()->toDateString(), null, null, null);

        $hasil = $this->attendance->checkIn($this->pegawai->id);

        $this->assertTrue($hasil->is_late);
    }

    #[Test]
    public function rekap_bulanan_menghitung_jumlah_terlambat(): void
    {
        $shift = $this->schedules->createShift([
            'code' => 'MLM', 'name' => 'Malam', 'start_time' => '00:00', 'end_time' => '23:59',
            'tolerance_minutes' => 0, 'is_active' => true,
        ]);
        $this->schedules->assignDuty($this->pegawai->id, $shift->id, now()->toDateString(), null, null, null);
        $this->attendance->checkIn($this->pegawai->id);

        $rekap = $this->attendance->monthlyRecap(\Carbon\CarbonImmutable::now())->firstWhere('pegawai.id', $this->pegawai->id);

        $this->assertSame(1, $rekap['terlambat']);
    }
}
