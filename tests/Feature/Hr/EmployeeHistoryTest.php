<?php

namespace Tests\Feature\Hr;

use App\Modules\Hr\Models\Employee;
use App\Modules\Hr\Models\EmployeeEducation;
use App\Modules\Hr\Models\EmployeeRecord;
use App\Modules\Hr\Models\PerformanceAppraisal;
use App\Modules\Hr\Services\EmployeeHistoryService;
use App\Modules\Hr\Services\EmployeeService;
use App\Modules\Hr\Services\HrException;
use App\Modules\Hr\Services\PerformanceAppraisalService;
use App\Modules\Platform\Database\Seeders\PermissionCatalogSeeder;
use App\Modules\Platform\Database\Seeders\RoleSeeder;
use App\Modules\Platform\Models\Role;
use App\Modules\Platform\Models\User;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class EmployeeHistoryTest extends TestCase
{
    use RefreshDatabase;

    private EmployeeService $employees;
    private EmployeeHistoryService $history;
    private PerformanceAppraisalService $appraisals;
    private User $adminHr;
    private Employee $pegawai;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([PermissionCatalogSeeder::class, RoleSeeder::class, ReferenceDataSeeder::class]);

        $this->employees = app(EmployeeService::class);
        $this->history = app(EmployeeHistoryService::class);
        $this->appraisals = app(PerformanceAppraisalService::class);

        $this->adminHr = User::query()->create([
            'username' => 'uji-riwayat-hr', 'name' => 'Admin HR Uji', 'password' => 'password', 'is_active' => true,
        ]);
        $this->adminHr->roles()->attach(Role::query()->where('code', 'admin-hr')->firstOrFail());

        $this->pegawai = $this->employees->createEmployee([
            'employee_number' => 'PEG100', 'name' => 'Siti Nurhaliza', 'position' => 'Perawat Pelaksana',
            'employment_type' => 'tetap', 'hire_date' => '2020-01-01', 'is_active' => true,
        ]);
    }

    #[Test]
    public function pegawai_baru_langsung_punya_satu_riwayat_jabatan_terbuka(): void
    {
        $riwayat = $this->pegawai->positionHistory()->get();

        $this->assertCount(1, $riwayat);
        $this->assertSame('Perawat Pelaksana', $riwayat->first()->position);
        $this->assertNull($riwayat->first()->end_date);
    }

    #[Test]
    public function mencatat_jabatan_baru_menutup_riwayat_lama_dan_memperbarui_snapshot(): void
    {
        $this->history->recordPositionChange($this->pegawai, [
            'position' => 'Kepala Ruangan', 'effective_date' => '2024-01-01', 'sk_number' => 'SK/001/2024',
        ]);

        $riwayatLama = $this->pegawai->positionHistory()->where('position', 'Perawat Pelaksana')->firstOrFail();
        $this->assertSame('2023-12-31', $riwayatLama->end_date->toDateString());

        $riwayatBaru = $this->pegawai->positionHistory()->where('position', 'Kepala Ruangan')->firstOrFail();
        $this->assertNull($riwayatBaru->end_date);

        $this->assertSame('Kepala Ruangan', $this->pegawai->fresh()->position);
    }

    #[Test]
    public function riwayat_gaji_tidak_punya_method_update_di_service(): void
    {
        $this->assertFalse(method_exists(EmployeeHistoryService::class, 'updateSalary'));

        $riwayat = $this->history->recordSalaryChange($this->pegawai, [
            'effective_date' => '2024-01-01', 'base_salary' => 5000000,
        ]);

        $this->assertSame('5000000.00', $riwayat->base_salary);
    }

    #[Test]
    public function riwayat_pendidikan_bisa_ditambah_dan_diubah(): void
    {
        $pendidikan = $this->history->addEducation($this->pegawai, [
            'education_level' => 's1', 'institution_name' => 'Universitas Indonesia',
            'major' => 'Keperawatan', 'graduation_year' => 2018,
        ]);

        $this->assertInstanceOf(EmployeeEducation::class, $pendidikan);

        $diperbarui = $this->history->updateEducation($pendidikan, [
            'education_level' => 's2', 'institution_name' => 'Universitas Indonesia',
            'major' => 'Keperawatan Anak', 'graduation_year' => 2022,
        ]);

        $this->assertSame('s2', $diperbarui->education_level);
    }

    #[Test]
    public function catatan_penghargaan_dan_peringatan_tersimpan_dengan_jenis_masing_masing(): void
    {
        $this->history->addRecord($this->pegawai, [
            'record_type' => EmployeeRecord::TYPE_PENGHARGAAN, 'record_date' => '2024-05-01', 'title' => 'Pegawai Teladan',
        ]);
        $this->history->addRecord($this->pegawai, [
            'record_type' => EmployeeRecord::TYPE_PERINGATAN, 'record_date' => '2024-06-01', 'title' => 'SP 1 — Keterlambatan',
        ]);

        $this->assertCount(2, $this->pegawai->records()->get());
        $this->assertSame(1, $this->pegawai->records()->where('record_type', 'penghargaan')->count());
        $this->assertSame(1, $this->pegawai->records()->where('record_type', 'peringatan')->count());
    }

    #[Test]
    public function catatan_kegiatan_ilmiah_dan_penelitian_tersimpan_dengan_keterangan(): void
    {
        $ilmiah = $this->history->addRecord($this->pegawai, [
            'record_type' => EmployeeRecord::TYPE_KEGIATAN_ILMIAH, 'record_date' => '2024-07-01',
            'title' => 'Pelatihan BTCLS', 'description' => 'Diselenggarakan oleh PPNI', 'document_number' => 'SERT/045/2024',
        ]);
        $penelitian = $this->history->addRecord($this->pegawai, [
            'record_type' => EmployeeRecord::TYPE_PENELITIAN, 'record_date' => '2024-08-01',
            'title' => 'Efektivitas Cuci Tangan pada Pencegahan Infeksi', 'description' => 'Jurnal Keperawatan Indonesia',
        ]);

        $this->assertSame('kegiatan_ilmiah', $ilmiah->fresh()->record_type);
        $this->assertSame('Diselenggarakan oleh PPNI', $ilmiah->fresh()->description);
        $this->assertSame('penelitian', $penelitian->fresh()->record_type);
        $this->assertSame('Kegiatan Ilmiah & Pelatihan', EmployeeRecord::typeLabel('kegiatan_ilmiah'));
        $this->assertSame('Penelitian', EmployeeRecord::typeLabel('penelitian'));
    }

    #[Test]
    public function skp_final_tidak_bisa_diubah_atau_difinalisasi_ulang(): void
    {
        $penilaian = $this->appraisals->record($this->pegawai, [
            'period' => '2026', 'score' => 88,
        ], $this->adminHr->id);

        $this->appraisals->finalize($penilaian);

        $this->expectException(HrException::class);
        $this->appraisals->record($this->pegawai, ['period' => '2026', 'score' => 95], $this->adminHr->id);
    }

    #[Test]
    public function predikat_skp_dihitung_dari_skor(): void
    {
        $penilaian = $this->appraisals->record($this->pegawai, ['period' => '2026', 'score' => 95], $this->adminHr->id);
        $this->assertSame('Sangat Baik', $penilaian->predicate());

        $penilaian = $this->appraisals->record($this->pegawai, ['period' => '2026', 'score' => 55], $this->adminHr->id);
        $this->assertSame('Kurang', $penilaian->predicate());
    }

    #[Test]
    public function layar_riwayat_pegawai_dan_skp_hanya_untuk_admin_hr(): void
    {
        $this->actingAs($this->adminHr)->get(route('hr.pegawai.detail', $this->pegawai))->assertOk();
        $this->actingAs($this->adminHr)->get(route('hr.skp.index'))->assertOk();
        $this->actingAs($this->adminHr)->get(route('hr.presensi.bulanan'))->assertOk();

        $dokter = User::query()->create([
            'username' => 'uji-dokter-skp', 'name' => 'Dokter Uji', 'password' => 'password', 'is_active' => true,
        ]);
        $dokter->roles()->attach(Role::query()->where('code', 'dokter')->firstOrFail());

        $this->actingAs($dokter)->get(route('hr.pegawai.detail', $this->pegawai))->assertForbidden();
        $this->actingAs($dokter)->get(route('hr.skp.index'))->assertForbidden();
    }

    #[Test]
    public function form_riwayat_jabatan_bisa_disubmit_lewat_http(): void
    {
        $this->actingAs($this->adminHr)->post(route('hr.pegawai.jabatan.simpan', $this->pegawai), [
            'position' => 'Kepala Ruangan',
            'effective_date' => '2025-01-01',
            'sk_number' => 'SK/010/2025',
        ])->assertRedirect();

        $this->assertSame('Kepala Ruangan', $this->pegawai->fresh()->position);
    }

    #[Test]
    public function form_catatan_kepegawaian_bisa_disubmit_lewat_http_dengan_keterangan(): void
    {
        $this->actingAs($this->adminHr)->post(route('hr.pegawai.catatan.simpan', $this->pegawai), [
            'record_type' => EmployeeRecord::TYPE_KEGIATAN_ILMIAH,
            'record_date' => '2025-02-01',
            'title' => 'Seminar Nasional Keperawatan',
            'description' => 'Diselenggarakan oleh RSP UI',
        ])->assertRedirect();

        $catatan = $this->pegawai->records()->where('record_type', 'kegiatan_ilmiah')->firstOrFail();
        $this->assertSame('Diselenggarakan oleh RSP UI', $catatan->description);
    }
}
