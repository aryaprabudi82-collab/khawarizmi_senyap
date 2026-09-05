<?php

namespace App\Modules\Identity\Http\Controllers;

use App\Modules\Identity\Models\Patient;
use App\Modules\Identity\Services\DuplicatePatientException;
use App\Modules\Identity\Services\PatientRegistry;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class PatientController
{
    public function __construct(private readonly PatientRegistry $patients) {}

    public function index(Request $request): View
    {
        $cari = trim((string) $request->query('cari', ''));

        return view('identity::patients.index', [
            'cari' => $cari,
            'pasien' => Patient::query()
                ->search($cari)
                ->orderByDesc('id')
                ->paginate(25)
                ->withQueryString(),
        ]);
    }

    public function create(Request $request): View
    {
        return view('identity::patients.create', [
            'namaAwal' => trim((string) $request->query('nama', '')),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:150'],
            'nik' => ['nullable', 'string', 'size:16'],
            'sex' => ['required', 'in:L,P'],
            'birth_place' => ['nullable', 'string', 'max:100'],
            'birth_date' => ['nullable', 'date', 'before_or_equal:today'],
            'mother_name' => ['nullable', 'string', 'max:100'],
            'blood_type' => ['nullable', 'string', 'max:3'],
            'religion' => ['nullable', 'string', 'max:30'],
            'marital_status' => ['nullable', 'string', 'max:30'],
            'education' => ['nullable', 'string', 'max:30'],
            'occupation' => ['nullable', 'string', 'max:80'],
            'employer' => ['nullable', 'string', 'max:150'],
            'ethnicity' => ['nullable', 'string', 'max:60'],
            'language' => ['nullable', 'string', 'max:60'],
            'inpatient_classification' => ['nullable', 'string', 'max:60'],
            'address' => ['nullable', 'string', 'max:255'],
            'village_name' => ['nullable', 'string', 'max:100'],
            'district_name' => ['nullable', 'string', 'max:100'],
            'city_name' => ['nullable', 'string', 'max:100'],
            'province_name' => ['nullable', 'string', 'max:100'],
            'phone' => ['nullable', 'string', 'max:40'],
            'guardian_name' => ['nullable', 'string', 'max:150'],
            'guardian_relation' => ['nullable', 'string', 'max:40'],
            'guardian_phone' => ['nullable', 'string', 'max:40'],
        ], [], [
            'name' => 'nama pasien',
            'sex' => 'jenis kelamin',
            'birth_date' => 'tanggal lahir',
            'mother_name' => 'nama ibu kandung',
        ]);

        try {
            $pasien = $this->patients->register($data, $request->user()?->id);
        } catch (DuplicatePatientException $e) {
            return back()->withInput()->with(
                'galat',
                $e->getMessage() . ' Nomor RM ' . $e->existing->medical_record_number . '.'
            );
        }

        // Alur yang paling sering: pasien baru langsung didaftarkan berobat.
        return redirect()
            ->route('registrasi.create', [
                'cari' => $pasien->medical_record_number,
                'pasien_id' => $pasien->id,
            ])
            ->with('sukses', "Pasien {$pasien->name} terdaftar dengan nomor RM {$pasien->medical_record_number}.");
    }
}
