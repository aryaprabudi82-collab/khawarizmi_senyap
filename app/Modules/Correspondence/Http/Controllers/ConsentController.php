<?php

namespace App\Modules\Correspondence\Http\Controllers;

use App\Modules\Correspondence\Models\ConsentInformationItem;
use App\Modules\Correspondence\Models\ConsentTemplate;
use App\Modules\Correspondence\Models\PatientConsent;
use App\Modules\Correspondence\Models\RefusalReason;
use App\Modules\Correspondence\Services\ConsentService;
use App\Modules\Correspondence\Services\CorrespondenceException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class ConsentController
{
    public function __construct(private readonly ConsentService $consents) {}

    public function index(): View
    {
        return view('correspondence::persetujuan.index', [
            'persetujuan' => PatientConsent::query()->with('items')->latest('signed_at')->limit(50)->get(),
            'template' => ConsentTemplate::query()->where('is_active', true)->orderBy('name')->get(),
            'alasan' => RefusalReason::query()->where('is_active', true)->orderBy('name')->get(),
            'hubungan' => PatientConsent::HUBUNGAN,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'consent_type' => ['required', Rule::in(PatientConsent::TYPES)],
            'registration_id' => ['nullable', 'integer'],
            'patient_id' => ['nullable', 'integer'],
            'patient_name' => ['required', 'string', 'max:150'],
            'procedure_description' => ['required', 'string', 'max:2000'],
            'decision' => ['required', 'in:setuju,menolak'],
            'witness_name' => ['nullable', 'string', 'max:150'],

            'signer_name' => ['nullable', 'string', 'max:150'],
            'signer_relationship' => ['nullable', Rule::in(PatientConsent::HUBUNGAN)],
            'signer_id_number' => ['nullable', 'string', 'max:30'],
            'signer_birth_date' => ['nullable', 'date'],
            'signer_sex' => ['nullable', 'in:L,P'],
            'signer_address' => ['nullable', 'string', 'max:200'],
            'signer_phone' => ['nullable', 'string', 'max:30'],
            'delegation_reason' => ['nullable', 'string', 'max:500'],

            'explained_by' => ['nullable', 'integer'],
            'explained_by_name' => ['nullable', 'string', 'max:150'],
            'refusal_reason_id' => ['nullable', 'integer', 'exists:App\Modules\Correspondence\Models\RefusalReason,id'],
            'refusal_risk_explained' => ['nullable', 'string', 'max:1000'],
            'chosen_practitioner_id' => ['nullable', 'integer'],
            'chosen_practitioner_name' => ['nullable', 'string', 'max:150'],
        ], [], [
            'consent_type' => 'jenis persetujuan', 'patient_name' => 'nama pasien',
            'procedure_description' => 'uraian tindakan', 'decision' => 'keputusan',
            'witness_name' => 'nama saksi', 'signer_name' => 'nama penanda tangan',
            'signer_relationship' => 'hubungan penanda tangan',
            'delegation_reason' => 'alasan perwakilan',
            'refusal_risk_explained' => 'akibat penolakan yang dijelaskan',
        ]);

        // Dokter pilihan hanya sah pada pernyataan memilih DPJP; dikosongkan
        // di sini supaya string kosong dari formulir tidak lolos jadi nilai.
        if ($data['consent_type'] !== PatientConsent::JENIS_MEMILIH_DPJP) {
            $data['chosen_practitioner_id'] = null;
            $data['chosen_practitioner_name'] = null;
        }

        try {
            $persetujuan = $this->consents->issue($data, $request->user()->id);
        } catch (CorrespondenceException $e) {
            return back()->withInput()->with('galat', $e->getMessage());
        }

        return back()->with('sukses', "Persetujuan {$persetujuan->consent_number} tersimpan.");
    }

    /** Persetujuan tindakan dari template, lengkap dengan butir penjelasannya. */
    public function storeFromTemplate(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'template_id' => ['required', 'integer', 'exists:App\Modules\Correspondence\Models\ConsentTemplate,id'],
            'registration_id' => ['nullable', 'integer'],
            'patient_id' => ['nullable', 'integer'],
            'patient_name' => ['required', 'string', 'max:150'],
            'procedure_description' => ['required', 'string', 'max:2000'],

            'signer_name' => ['nullable', 'string', 'max:150'],
            'signer_relationship' => ['nullable', Rule::in(PatientConsent::HUBUNGAN)],
            'signer_id_number' => ['nullable', 'string', 'max:30'],
            'signer_birth_date' => ['nullable', 'date'],
            'signer_sex' => ['nullable', 'in:L,P'],
            'signer_address' => ['nullable', 'string', 'max:200'],
            'signer_phone' => ['nullable', 'string', 'max:30'],
            'delegation_reason' => ['nullable', 'string', 'max:500'],

            'explained_by' => ['nullable', 'integer'],
            'explained_by_name' => ['nullable', 'string', 'max:150'],
        ], [], [
            'template_id' => 'template', 'patient_name' => 'nama pasien',
            'procedure_description' => 'uraian tindakan',
            'signer_relationship' => 'hubungan penanda tangan',
            'delegation_reason' => 'alasan perwakilan',
        ]);

        $template = ConsentTemplate::query()->findOrFail($data['template_id']);
        unset($data['template_id']);

        try {
            // Jenis persetujuan sengaja tidak dikirim dari sini — service
            // mengambilnya dari template.
            $persetujuan = $this->consents->issueFromTemplate($template, $data, $request->user()->id);
        } catch (CorrespondenceException $e) {
            return back()->withInput()->with('galat', $e->getMessage());
        }

        return back()->with('sukses', "Persetujuan {$persetujuan->consent_number} dibuat; butir penjelasannya menunggu dikonfirmasi.");
    }

    public function confirmItem(Request $request, ConsentInformationItem $butir): RedirectResponse
    {
        $data = $request->validate([
            // 'belum' dipakai untuk mengembalikan butir ke keadaan belum
            // dijelaskan; bukan sinonim "tidak paham".
            'confirmed' => ['required', 'in:ya,tidak,belum'],
            'confirmation_note' => ['nullable', 'string', 'max:1000'],
        ], [], ['confirmed' => 'hasil konfirmasi', 'confirmation_note' => 'keterangan']);

        $nilai = match ($data['confirmed']) {
            'ya' => true,
            'tidak' => false,
            default => null,
        };

        try {
            $this->consents->confirmItem($butir, $nilai, $data['confirmation_note'] ?? null);
        } catch (CorrespondenceException $e) {
            return back()->with('galat', $e->getMessage());
        }

        return back()->with('sukses', "Butir \"{$butir->label}\" diperbarui.");
    }

    public function decide(Request $request, PatientConsent $persetujuan): RedirectResponse
    {
        $data = $request->validate([
            'decision' => ['required', 'in:setuju,menolak'],
            'signer_name' => ['nullable', 'string', 'max:150'],
            'witness_name' => ['nullable', 'string', 'max:150'],
        ], [], ['decision' => 'keputusan', 'signer_name' => 'nama penanda tangan', 'witness_name' => 'nama saksi']);

        try {
            $this->consents->decide(
                $persetujuan,
                $data['decision'],
                $data['signer_name'] ?? null,
                $data['witness_name'] ?? null
            );
        } catch (CorrespondenceException $e) {
            return back()->with('galat', $e->getMessage());
        }

        return back()->with('sukses', "Persetujuan {$persetujuan->consent_number} direkam sebagai {$data['decision']}.");
    }

    public function cancel(PatientConsent $persetujuan): RedirectResponse
    {
        try {
            $this->consents->cancel($persetujuan);
        } catch (CorrespondenceException $e) {
            return back()->with('galat', $e->getMessage());
        }

        return back()->with('sukses', "Persetujuan {$persetujuan->consent_number} dibatalkan.");
    }

    public function print(PatientConsent $persetujuan): View
    {
        return view('correspondence::persetujuan.cetak', ['persetujuan' => $persetujuan->load('items')]);
    }
}
