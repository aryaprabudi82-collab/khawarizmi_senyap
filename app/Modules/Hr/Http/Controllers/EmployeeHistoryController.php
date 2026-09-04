<?php

namespace App\Modules\Hr\Http\Controllers;

use App\Modules\Hr\Models\DocumentType;
use App\Modules\Hr\Models\Employee;
use App\Modules\Hr\Models\EmployeeDocument;
use App\Modules\Hr\Models\EmployeeEducation;
use App\Modules\Hr\Models\EmployeeRecord;
use App\Modules\Hr\Services\EmployeeHistoryService;
use App\Modules\Hr\Services\OrganizationContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class EmployeeHistoryController
{
    public function __construct(
        private readonly EmployeeHistoryService $history,
        private readonly OrganizationContext $organization,
    ) {}

    public function show(Employee $pegawai): View
    {
        return view('hr::pegawai.detail', [
            'pegawai' => $pegawai->load(['positionHistory', 'salaryHistory', 'educations', 'records', 'appraisals', 'documents']),
            'unit' => $this->organization->units(),
            'jenisBerkas' => DocumentType::query()->where('is_active', true)->orderBy('name')->get(),
        ]);
    }

    public function storePosition(Request $request, Employee $pegawai): RedirectResponse
    {
        $data = $request->validate([
            'position' => ['required', 'string', 'max:100'],
            'unit_id' => ['nullable', 'integer'],
            'effective_date' => ['required', 'date'],
            'sk_number' => ['nullable', 'string', 'max:60'],
            'note' => ['nullable', 'string'],
        ], [], ['position' => 'jabatan', 'unit_id' => 'unit', 'effective_date' => 'tanggal efektif', 'sk_number' => 'nomor SK']);

        $this->history->recordPositionChange($pegawai, $data);

        return back()->with('sukses', 'Riwayat jabatan dicatat.');
    }

    public function storeSalary(Request $request, Employee $pegawai): RedirectResponse
    {
        $data = $request->validate([
            'effective_date' => ['required', 'date'],
            'base_salary' => ['required', 'numeric', 'min:1'],
            'sk_number' => ['nullable', 'string', 'max:60'],
            'note' => ['nullable', 'string'],
        ], [], ['effective_date' => 'tanggal efektif', 'base_salary' => 'gaji pokok', 'sk_number' => 'nomor SK']);

        $this->history->recordSalaryChange($pegawai, $data);

        return back()->with('sukses', 'Riwayat gaji dicatat.');
    }

    public function storeEducation(Request $request, Employee $pegawai): RedirectResponse
    {
        $data = $this->validateEducation($request);

        $this->history->addEducation($pegawai, $data);

        return back()->with('sukses', 'Riwayat pendidikan ditambahkan.');
    }

    public function updateEducation(Request $request, Employee $pegawai, EmployeeEducation $pendidikan): RedirectResponse
    {
        $data = $this->validateEducation($request);

        $this->history->updateEducation($pendidikan, $data);

        return back()->with('sukses', 'Riwayat pendidikan diperbarui.');
    }

    public function storeRecord(Request $request, Employee $pegawai): RedirectResponse
    {
        $data = $this->validateRecord($request);

        $this->history->addRecord($pegawai, $data);

        return back()->with('sukses', 'Catatan kepegawaian ditambahkan.');
    }

    public function updateRecord(Request $request, Employee $pegawai, EmployeeRecord $catatan): RedirectResponse
    {
        $data = $this->validateRecord($request);

        $this->history->updateRecord($catatan, $data);

        return back()->with('sukses', 'Catatan kepegawaian diperbarui.');
    }

    public function storeDocument(Request $request, Employee $pegawai): RedirectResponse
    {
        $data = $request->validate([
            'document_type_id' => ['required', 'integer'],
            'file' => ['required', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:5120'],
            'document_number' => ['nullable', 'string', 'max:60'],
            'issued_date' => ['nullable', 'date'],
            'expiry_date' => ['nullable', 'date'],
            'note' => ['nullable', 'string', 'max:255'],
        ], [], [
            'document_type_id' => 'jenis berkas', 'file' => 'berkas',
            'issued_date' => 'tanggal terbit', 'expiry_date' => 'tanggal berlaku sampai',
        ]);

        $jenis = DocumentType::query()->findOrFail($data['document_type_id']);
        $file = $data['file'];
        unset($data['document_type_id'], $data['file']);

        $this->history->uploadDocument($pegawai, $jenis, $file, $data, $request->user());

        return back()->with('sukses', 'Berkas tersimpan.');
    }

    /** Diunduh lewat rute yang diperiksa permission-nya sendiri — lihat catatan migrasi hr::employee_documents. */
    public function downloadDocument(Employee $pegawai, EmployeeDocument $berkas): StreamedResponse
    {
        abort_unless($berkas->employee_id === $pegawai->id, 404);

        return Storage::disk('local')->download($berkas->file_path, $berkas->original_filename);
    }

    private function validateEducation(Request $request): array
    {
        return $request->validate([
            'education_level' => ['required', Rule::in(EmployeeEducation::LEVELS)],
            'institution_name' => ['required', 'string', 'max:150'],
            'major' => ['nullable', 'string', 'max:100'],
            'graduation_year' => ['required', 'integer', 'min:1950', 'max:' . (date('Y') + 1)],
            'certificate_number' => ['nullable', 'string', 'max:60'],
        ], [], [
            'education_level' => 'jenjang', 'institution_name' => 'nama institusi', 'major' => 'jurusan',
            'graduation_year' => 'tahun lulus', 'certificate_number' => 'nomor sertifikat/ijazah',
        ]);
    }

    private function validateRecord(Request $request): array
    {
        return $request->validate([
            'record_type' => ['required', Rule::in([EmployeeRecord::TYPE_PENGHARGAAN, EmployeeRecord::TYPE_PERINGATAN])],
            'record_date' => ['required', 'date'],
            'title' => ['required', 'string', 'max:150'],
            'description' => ['nullable', 'string'],
            'document_number' => ['nullable', 'string', 'max:60'],
        ], [], [
            'record_type' => 'jenis catatan', 'record_date' => 'tanggal', 'title' => 'judul', 'document_number' => 'nomor dokumen',
        ]);
    }
}
