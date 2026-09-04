<?php

namespace Tests\Feature\Hr;

use App\Modules\Hr\Models\DocumentType;
use App\Modules\Hr\Models\Employee;
use App\Modules\Hr\Services\EmployeeHistoryService;
use App\Modules\Hr\Services\EmployeeService;
use App\Modules\Platform\Database\Seeders\PermissionCatalogSeeder;
use App\Modules\Platform\Database\Seeders\RoleSeeder;
use App\Modules\Platform\Models\Role;
use App\Modules\Platform\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class EmployeeDocumentTest extends TestCase
{
    use RefreshDatabase;

    private EmployeeHistoryService $history;
    private User $adminHr;
    private Employee $pegawai;
    private DocumentType $jenisBerkas;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([PermissionCatalogSeeder::class, RoleSeeder::class]);

        Storage::fake('local');

        $this->history = app(EmployeeHistoryService::class);

        $this->adminHr = User::query()->create([
            'username' => 'uji-berkas', 'name' => 'Admin HR Uji', 'password' => 'password', 'is_active' => true,
        ]);
        $this->adminHr->roles()->attach(Role::query()->where('code', 'admin-hr')->firstOrFail());

        $this->pegawai = app(EmployeeService::class)->createEmployee([
            'employee_number' => 'PEG001', 'name' => 'Rudi Hartono', 'position' => 'Perawat',
            'employment_type' => 'tetap', 'hire_date' => '2020-01-01', 'is_active' => true,
        ]);

        $this->jenisBerkas = app(EmployeeService::class)->createDocumentType([
            'code' => 'KTP', 'name' => 'KTP', 'is_required' => true, 'is_active' => true,
        ]);
    }

    #[Test]
    public function berkas_tersimpan_di_disk_local_bukan_public(): void
    {
        $file = UploadedFile::fake()->create('ktp.pdf', 100, 'application/pdf');

        $dokumen = $this->history->uploadDocument($this->pegawai, $this->jenisBerkas, $file, [
            'document_number' => '3201xxxx',
        ], $this->adminHr);

        Storage::disk('local')->assertExists($dokumen->file_path);
        $this->assertSame('ktp.pdf', $dokumen->original_filename);
        $this->assertSame('KTP', $dokumen->document_type_name);
    }

    #[Test]
    public function berkas_dengan_tanggal_berlaku_terdeteksi_kedaluwarsa(): void
    {
        $file = UploadedFile::fake()->create('sip.pdf', 50, 'application/pdf');

        $dokumen = $this->history->uploadDocument($this->pegawai, $this->jenisBerkas, $file, [
            'expiry_date' => now()->subDay()->toDateString(),
        ], $this->adminHr);

        $this->assertTrue($dokumen->isExpired());
        $this->assertFalse($dokumen->isExpiringSoon());
    }

    #[Test]
    public function admin_hr_dapat_mengunggah_berkas_lewat_layar(): void
    {
        $file = UploadedFile::fake()->create('ijazah.pdf', 200, 'application/pdf');

        $this->actingAs($this->adminHr)
            ->post(route('hr.pegawai.berkas.simpan', $this->pegawai), [
                'document_type_id' => $this->jenisBerkas->id,
                'file' => $file,
            ])
            ->assertRedirect()
            ->assertSessionHas('sukses');

        $this->assertDatabaseHas('hr.employee_documents', [
            'employee_id' => $this->pegawai->id, 'document_type_id' => $this->jenisBerkas->id,
        ]);
    }

    #[Test]
    public function admin_hr_dapat_mengunduh_berkas_lewat_layar(): void
    {
        $file = UploadedFile::fake()->create('ijazah.pdf', 200, 'application/pdf');
        $dokumen = $this->history->uploadDocument($this->pegawai, $this->jenisBerkas, $file, [], $this->adminHr);

        $this->actingAs($this->adminHr)
            ->get(route('hr.pegawai.berkas.unduh', [$this->pegawai, $dokumen]))
            ->assertOk();
    }

    #[Test]
    public function berkas_pegawai_lain_tidak_bisa_diunduh_lewat_rute_pegawai_ini(): void
    {
        $pegawaiLain = app(EmployeeService::class)->createEmployee([
            'employee_number' => 'PEG002', 'name' => 'Sari Wulandari', 'position' => 'Bidan',
            'employment_type' => 'tetap', 'hire_date' => '2021-01-01', 'is_active' => true,
        ]);
        $file = UploadedFile::fake()->create('ijazah.pdf', 200, 'application/pdf');
        $dokumen = $this->history->uploadDocument($pegawaiLain, $this->jenisBerkas, $file, [], $this->adminHr);

        $this->actingAs($this->adminHr)
            ->get(route('hr.pegawai.berkas.unduh', [$this->pegawai, $dokumen]))
            ->assertNotFound();
    }

    #[Test]
    public function jenis_berkas_tersimpan(): void
    {
        $jenis = app(EmployeeService::class)->createDocumentType([
            'code' => 'IJZ', 'name' => 'Ijazah', 'is_required' => true, 'is_active' => true,
        ]);

        $this->assertTrue($jenis->is_required);
    }

    #[Test]
    public function peran_lain_ditolak_mengunggah_berkas(): void
    {
        $petugasDaftar = User::query()->create([
            'username' => 'uji-daftar-berkas', 'name' => 'Petugas Daftar', 'password' => 'password', 'is_active' => true,
        ]);
        $petugasDaftar->roles()->attach(Role::query()->where('code', 'petugas-daftar')->firstOrFail());

        $file = UploadedFile::fake()->create('ktp.pdf', 100, 'application/pdf');

        $this->actingAs($petugasDaftar)
            ->post(route('hr.pegawai.berkas.simpan', $this->pegawai), [
                'document_type_id' => $this->jenisBerkas->id,
                'file' => $file,
            ])
            ->assertForbidden();
    }
}
