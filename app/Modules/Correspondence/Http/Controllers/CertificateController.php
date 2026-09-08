<?php

namespace App\Modules\Correspondence\Http\Controllers;

use App\Modules\Correspondence\Models\ControlLetter;
use App\Modules\Correspondence\Models\MedicalCertificate;
use App\Modules\Correspondence\Services\CertificateService;
use App\Modules\Correspondence\Services\ControlLetterService;
use App\Modules\Correspondence\Services\CorrespondenceException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class CertificateController
{
    public function __construct(
        private readonly CertificateService $certificates,
        private readonly ControlLetterService $suratKontrol,
    ) {}

    public function index(): View
    {
        return view('correspondence::keterangan.index', [
            'surat' => MedicalCertificate::query()->latest('issued_at')->limit(50)->get(),
            'kontrol' => ControlLetter::query()->latest('issued_at')->limit(50)->get(),

            // Surat kontrol yang tanggalnya lewat tapi pasiennya tidak
            // datang — ditampilkan terpisah karena daftar terbaru justru
            // menyembunyikannya.
            'kontrolTerlewat' => $this->suratKontrol->missed(),
            'jenisBertemuan' => MedicalCertificate::JENIS_BERTEMUAN,
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

            'diagnosis' => ['nullable', 'string', 'max:200'],
            'examination_result' => ['nullable', 'string', 'max:1000'],
            // 'ya'/'tidak' dan bukan boolean langsung, supaya "belum dipilih"
            // tetap terbedakan dari "tidak" pada formulir HTML.
            'kesimpulan' => ['nullable', 'in:ya,tidak'],

            'third_party_name' => ['nullable', 'string', 'max:150'],
            'third_party_relationship' => ['nullable', 'string', 'max:20'],
            'third_party_birth_date' => ['nullable', 'date'],
            'third_party_sex' => ['nullable', 'in:L,P'],
            'third_party_address' => ['nullable', 'string', 'max:200'],
            'third_party_occupation' => ['nullable', 'string', 'max:60'],
            'third_party_institution' => ['nullable', 'string', 'max:100'],
        ], [], [
            'certificate_type' => 'jenis surat', 'patient_name' => 'nama pasien', 'purpose' => 'keperluan',
            'content' => 'isi keterangan', 'valid_from' => 'berlaku sejak', 'valid_until' => 'berlaku sampai',
            'examination_result' => 'temuan pemeriksaan', 'kesimpulan' => 'kesimpulan pemeriksaan',
            'third_party_name' => 'nama pihak kedua',
        ]);

        $kesimpulan = $data['kesimpulan'] ?? null;
        unset($data['kesimpulan']);

        $data['is_clear'] = match ($kesimpulan) {
            'ya' => true,
            'tidak' => false,
            default => null,
        };

        // Kolom yang tidak berlaku untuk jenisnya dikosongkan supaya string
        // kosong dari formulir tidak lolos jadi nilai.
        if ($data['certificate_type'] !== MedicalCertificate::JENIS_SAKIT_PIHAK_KEDUA) {
            foreach ([
                'third_party_name', 'third_party_relationship', 'third_party_birth_date',
                'third_party_sex', 'third_party_address', 'third_party_occupation',
                'third_party_institution',
            ] as $kolom) {
                $data[$kolom] = null;
            }
        }

        try {
            $surat = $this->certificates->issue($data, $request->user()->id);
        } catch (CorrespondenceException $e) {
            return back()->withInput()->with('galat', $e->getMessage());
        }

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

    // ------------------------------------------------------ surat kontrol

    public function storeControl(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'registration_id' => ['nullable', 'integer'],
            'patient_id' => ['nullable', 'integer'],
            'patient_name' => ['required', 'string', 'max:150'],
            'diagnosis' => ['required', 'string', 'max:200'],
            'therapy' => ['required', 'string', 'max:2000'],
            'control_reason' => ['required', 'string', 'max:1000'],
            'follow_up_plan' => ['required', 'string', 'max:1000'],
            'control_date' => ['required', 'date'],
            'practitioner_id' => ['nullable', 'integer'],
            'practitioner_name' => ['required', 'string', 'max:150'],
        ], [], [
            'patient_name' => 'nama pasien', 'therapy' => 'terapi',
            'control_reason' => 'alasan kontrol', 'follow_up_plan' => 'rencana tindak lanjut',
            'control_date' => 'tanggal kontrol', 'practitioner_name' => 'dokter',
        ]);

        try {
            $surat = $this->suratKontrol->issue($data, $request->user()->id, $request->user()->name);
        } catch (CorrespondenceException $e) {
            return back()->withInput()->with('galat', $e->getMessage());
        }

        return back()->with('sukses', "Surat kontrol {$surat->letter_number} diterbitkan.");
    }

    public function updateControl(Request $request, ControlLetter $kontrol): RedirectResponse
    {
        $data = $request->validate([
            'keputusan' => ['required', 'in:sudah-periksa,batal'],
            'status_note' => ['nullable', 'string', 'max:500'],
        ], [], ['keputusan' => 'status', 'status_note' => 'keterangan']);

        try {
            if ($data['keputusan'] === ControlLetter::STATUS_BATAL) {
                $this->suratKontrol->cancel($kontrol, (string) ($data['status_note'] ?? ''));
            } else {
                $this->suratKontrol->markSeen($kontrol);
            }
        } catch (CorrespondenceException $e) {
            return back()->with('galat', $e->getMessage());
        }

        return back()->with('sukses', "Surat kontrol {$kontrol->letter_number} diperbarui.");
    }
}
