<?php

namespace App\Modules\Correspondence\Http\Controllers;

use App\Modules\Correspondence\Models\MedicalCertificate;
use App\Modules\Correspondence\Services\CertificateService;
use App\Modules\Correspondence\Services\CorrespondenceException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class CertificateController
{
    public function __construct(private readonly CertificateService $certificates) {}

    public function index(): View
    {
        return view('correspondence::keterangan.index', [
            'surat' => MedicalCertificate::query()->latest('issued_at')->limit(50)->get(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'certificate_type' => ['required', Rule::in(MedicalCertificate::TYPES)],
            'registration_id' => ['nullable', 'integer'],
            'patient_id' => ['nullable', 'integer'],
            'patient_name' => ['required', 'string', 'max:150'],
            'purpose' => ['required', 'string', 'max:200'],
            'content' => ['required', 'string', 'max:2000'],
            'valid_from' => ['required', 'date'],
            'valid_until' => ['nullable', 'date', 'after_or_equal:valid_from'],
        ], [], [
            'certificate_type' => 'jenis surat', 'patient_name' => 'nama pasien', 'purpose' => 'keperluan',
            'content' => 'isi keterangan', 'valid_from' => 'berlaku sejak', 'valid_until' => 'berlaku sampai',
        ]);

        $surat = $this->certificates->issue($data, $request->user()->id);

        return back()->with('sukses', "Surat {$surat->certificate_number} diterbitkan.");
    }

    public function cancel(MedicalCertificate $surat): RedirectResponse
    {
        try {
            $this->certificates->cancel($surat);
        } catch (CorrespondenceException $e) {
            return back()->with('galat', $e->getMessage());
        }

        return back()->with('sukses', "Surat {$surat->certificate_number} dibatalkan.");
    }

    public function print(MedicalCertificate $surat): View
    {
        return view('correspondence::keterangan.cetak', ['surat' => $surat]);
    }
}
