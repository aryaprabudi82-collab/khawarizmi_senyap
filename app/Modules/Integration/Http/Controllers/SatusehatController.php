<?php

namespace App\Modules\Integration\Http\Controllers;

use App\Modules\Integration\Models\OutboundMessage;
use App\Modules\Integration\Services\IdentityMappingService;
use App\Modules\Integration\Services\OrganizationContext;
use App\Modules\Integration\Services\RegistrationContext;
use App\Modules\Integration\Services\Satusehat\ConditionSyncService;
use App\Modules\Integration\Services\Satusehat\EncounterSyncService;
use App\Modules\Integration\Services\Satusehat\PatientSyncService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use RuntimeException;

class SatusehatController
{
    public function __construct(
        private readonly PatientSyncService $patients,
        private readonly EncounterSyncService $encounters,
        private readonly ConditionSyncService $conditions,
        private readonly IdentityMappingService $mappings,
        private readonly OrganizationContext $organization,
        private readonly RegistrationContext $registrations,
    ) {}

    public function index(): View
    {
        return view('integration::satusehat.index', [
            'unit' => $this->organization->units(),
            'praktisi' => $this->organization->practitioners(),
            'pemetaanLokasi' => $this->mappings->allFor('satusehat', 'location', 'organization'),
            'pemetaanPraktisi' => $this->mappings->allFor('satusehat', 'practitioner', 'organization'),
            'kiriman' => OutboundMessage::query()->where('target_system', 'satusehat')->latest('updated_at')->limit(30)->get(),
        ]);
    }

    public function mapLocation(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'unit_id' => ['required', 'integer'],
            'satusehat_location_id' => ['required', 'string', 'max:128'],
        ], [], ['unit_id' => 'unit', 'satusehat_location_id' => 'ID Location SATUSEHAT']);

        $this->mappings->setManually('satusehat', 'location', 'organization', $data['unit_id'], $data['satusehat_location_id'], $request->user()->id);

        return back()->with('sukses', 'Pemetaan lokasi SATUSEHAT disimpan.');
    }

    public function mapPractitioner(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'practitioner_id' => ['required', 'integer'],
            'satusehat_practitioner_id' => ['required', 'string', 'max:128'],
        ], [], ['practitioner_id' => 'praktisi', 'satusehat_practitioner_id' => 'ID Practitioner SATUSEHAT']);

        $this->mappings->setManually('satusehat', 'practitioner', 'organization', $data['practitioner_id'], $data['satusehat_practitioner_id'], $request->user()->id);

        return back()->with('sukses', 'Pemetaan praktisi SATUSEHAT disimpan.');
    }

    public function syncEncounter(int $registrasi): RedirectResponse
    {
        $registration = $this->registrations->find($registrasi);

        if ($registration === null) {
            return back()->with('galat', 'Kunjungan tidak ditemukan.');
        }

        try {
            $this->patients->sync((int) $registration->patient_id);
            $pesan = $this->encounters->sync($registrasi);
        } catch (RuntimeException $e) {
            return back()->with('galat', $e->getMessage());
        }

        return back()->with($pesan->status === OutboundMessage::STATUS_SENT ? 'sukses' : 'galat', $pesan->error_message ?? 'Kunjungan tersinkron ke SATUSEHAT.');
    }

    public function syncCondition(int $registrasi): RedirectResponse
    {
        try {
            $pesan = $this->conditions->syncPrimary($registrasi);
        } catch (RuntimeException $e) {
            return back()->with('galat', $e->getMessage());
        }

        return back()->with($pesan->status === OutboundMessage::STATUS_SENT ? 'sukses' : 'galat', $pesan->error_message ?? 'Diagnosis tersinkron ke SATUSEHAT.');
    }
}
