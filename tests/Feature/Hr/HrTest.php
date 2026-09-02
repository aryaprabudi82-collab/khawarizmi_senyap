<?php

namespace Tests\Feature\Hr;

use App\Modules\Hr\Models\AttendanceRecord;
use App\Modules\Hr\Models\Employee;
use App\Modules\Hr\Models\LeaveRequest;
use App\Modules\Hr\Models\LeaveType;
use App\Modules\Hr\Services\AttendanceService;
use App\Modules\Hr\Services\EmployeeService;
use App\Modules\Hr\Services\HrException;
use App\Modules\Hr\Services\LeaveService;
use App\Modules\Platform\Database\Seeders\PermissionCatalogSeeder;
use App\Modules\Platform\Database\Seeders\RoleSeeder;
use App\Modules\Platform\Models\Role;
use App\Modules\Platform\Models\User;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class HrTest extends TestCase
{
    use RefreshDatabase;

    private EmployeeService $employees;
    private LeaveService $leave;
    private AttendanceService $attendance;
    private User $adminHr;
    private Employee $pegawai;
    private LeaveType $cutiTahunan;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([PermissionCatalogSeeder::class, RoleSeeder::class, ReferenceDataSeeder::class]);

        $this->employees = app(EmployeeService::class);
        $this->leave = app(LeaveService::class);
        $this->attendance = app(AttendanceService::class);

        $this->adminHr = User::query()->create([
            'username' => 'uji-admin-hr', 'name' => 'Admin HR Uji', 'password' => 'password', 'is_active' => true,
        ]);
        $this->adminHr->roles()->attach(Role::query()->where('code', 'admin-hr')->firstOrFail());

        $this->pegawai = $this->employees->createEmployee([
            'employee_number' => 'PEG001', 'name' => 'Rudi Hartono', 'position' => 'Perawat',
            'employment_type' => 'tetap', 'hire_date' => '2020-01-01', 'is_active' => true,
        ]);

        $this->cutiTahunan = $this->employees->createLeaveType([
            'code' => 'tahunan', 'name' => 'Cuti Tahunan', 'annual_quota' => 12, 'is_active' => true,
        ]);
    }

    #[Test]
    public function admin_hr_dapat_menambah_pegawai_baru(): void
    {
        $this->actingAs($this->adminHr)
            ->post(route('hr.pegawai.simpan'), [
                'employee_number' => 'PEG002', 'name' => 'Dewi Anggraini', 'position' => 'Apoteker',
                'employment_type' => 'kontrak', 'hire_date' => now()->toDateString(),
            ])
            ->assertRedirect()
            ->assertSessionHas('sukses');

        $this->assertDatabaseHas('hr.employees', ['employee_number' => 'PEG002', 'employment_type' => 'kontrak']);
    }

    #[Test]
    public function pengajuan_cuti_menghitung_jumlah_hari_dan_berstatus_diajukan(): void
    {
        $pengajuan = $this->leave->request(
            $this->pegawai->id, $this->cutiTahunan->id, '2026-01-05', '2026-01-09', 'Acara keluarga', $this->adminHr->id
        );

        $this->assertSame(5, $pengajuan->days_count);
        $this->assertSame(LeaveRequest::STATUS_DIAJUKAN, $pengajuan->status);
    }

    #[Test]
    public function pengajuan_cuti_melebihi_jatah_tahunan_ditolak(): void
    {
        $this->leave->approve(
            $this->leave->request($this->pegawai->id, $this->cutiTahunan->id, '2026-01-05', '2026-01-15', null, $this->adminHr->id),
            $this->adminHr->id
        ); // 11 hari terpakai & disetujui

        $this->expectException(HrException::class);
        $this->expectExceptionMessage('tersisa 1 hari');

        $this->leave->request($this->pegawai->id, $this->cutiTahunan->id, '2026-02-01', '2026-02-05', null, $this->adminHr->id); // minta 5 hari, sisa cuma 1
    }

    #[Test]
    public function pengajuan_cuti_yang_masih_diajukan_tidak_menahan_jatah_sampai_disetujui(): void
    {
        // Dua pengajuan 10 hari, keduanya masih 'diajukan' - tidak saling menghalangi
        // karena jatah dihitung dari yang sudah DISETUJUI, bukan yang diajukan.
        $this->leave->request($this->pegawai->id, $this->cutiTahunan->id, '2026-01-05', '2026-01-14', null, $this->adminHr->id);
        $kedua = $this->leave->request($this->pegawai->id, $this->cutiTahunan->id, '2026-03-05', '2026-03-14', null, $this->adminHr->id);

        $this->assertSame(LeaveRequest::STATUS_DIAJUKAN, $kedua->status);
    }

    #[Test]
    public function persetujuan_cuti_mengunci_status_dan_tidak_bisa_diputuskan_ulang(): void
    {
        $pengajuan = $this->leave->request($this->pegawai->id, $this->cutiTahunan->id, '2026-01-05', '2026-01-06', null, $this->adminHr->id);
        $disetujui = $this->leave->approve($pengajuan, $this->adminHr->id);

        $this->assertSame(LeaveRequest::STATUS_DISETUJUI, $disetujui->status);
        $this->assertSame($this->adminHr->id, $disetujui->approved_by);

        $this->expectException(HrException::class);
        $this->leave->reject($disetujui->refresh(), $this->adminHr->id, 'Coba tolak setelah disetujui');
    }

    #[Test]
    public function penolakan_cuti_mencatat_alasan(): void
    {
        $pengajuan = $this->leave->request($this->pegawai->id, $this->cutiTahunan->id, '2026-01-05', '2026-01-06', null, $this->adminHr->id);
        $ditolak = $this->leave->reject($pengajuan, $this->adminHr->id, 'Bentrok jadwal jaga');

        $this->assertSame(LeaveRequest::STATUS_DITOLAK, $ditolak->status);
        $this->assertSame('Bentrok jadwal jaga', $ditolak->rejection_reason);
    }

    #[Test]
    public function presensi_masuk_dan_pulang_tercatat_sekali_sehari(): void
    {
        $rekap = $this->attendance->checkIn($this->pegawai->id, $this->adminHr->id);
        $this->assertNotNull($rekap->check_in_at);

        $this->expectException(HrException::class);
        $this->expectExceptionMessage('Sudah presensi masuk');

        $this->attendance->checkIn($this->pegawai->id, $this->adminHr->id);
    }

    #[Test]
    public function presensi_pulang_tidak_bisa_sebelum_presensi_masuk(): void
    {
        $this->expectException(HrException::class);
        $this->expectExceptionMessage('Belum presensi masuk');

        $this->attendance->checkOut($this->pegawai->id);
    }

    #[Test]
    public function presensi_manual_mencatat_izin_tanpa_checkin(): void
    {
        $rekap = $this->attendance->recordManual($this->pegawai->id, '2026-01-10', AttendanceRecord::STATUS_IZIN, $this->adminHr->id, 'Keperluan keluarga');

        $this->assertSame(AttendanceRecord::STATUS_IZIN, $rekap->status);
        $this->assertNull($rekap->check_in_at);
    }

    #[Test]
    public function layar_kepegawaian_hanya_untuk_admin_hr(): void
    {
        $this->actingAs($this->adminHr)->get(route('hr.index'))->assertOk();
        $this->actingAs($this->adminHr)->get(route('hr.cuti.index'))->assertOk();
        $this->actingAs($this->adminHr)->get(route('hr.presensi.index'))->assertOk();

        $dokter = User::query()->create([
            'username' => 'uji-dokter-hr', 'name' => 'Dokter Uji', 'password' => 'password', 'is_active' => true,
        ]);
        $dokter->roles()->attach(Role::query()->where('code', 'dokter')->firstOrFail());

        $this->actingAs($dokter)->get(route('hr.index'))->assertForbidden();
    }
}
