<?php

namespace App\Modules\Encounter\Http\Controllers;

use App\Modules\Encounter\Models\KfrProgramRequest;
use App\Modules\Encounter\Models\Registration;
use App\Modules\Encounter\Services\KfrProgramRequestService;
use App\Modules\Encounter\Services\RegistrationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** layanan_program_kfr — lihat catatan migrasi encounter untuk kenapa terpisah dari registrasi biasa. */
class KfrProgramRequestController
{
    public function __construct(private readonly KfrProgramRequestService $requests) {}

    public function index(): View
    {
        return view('encounter::kfr-requests.index', [
            'permintaan' => KfrProgramRequest::query()->latest('requested_at')->limit(50)->get(),
            'kunjunganHariIni' => Registration::query()
                ->whereDate('service_date', now()->toDateString())
                ->where('status', '<>', Registration::STATUS_BATAL)
                ->orderBy('patient_name')
                ->get(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'registration_id' => ['required', 'integer'],
            'program_name' => ['required', 'string', 'max:200'],
            'reason' => ['required', 'string', 'max:2000'],
        ], [], [
            'registration_id' => 'kunjungan', 'program_name' => 'nama program KFR', 'reason' => 'alasan permintaan',
        ]);

        $registrasi = Registration::query()->find($data['registration_id']);

        if ($registrasi === null) {
            return back()->withInput()->with('galat', 'Kunjungan tidak ditemukan.');
        }

        unset($data['registration_id']);

        $permintaan = $this->requests->issue($registrasi, $data, $request->user());

        return back()->with('sukses', "Permintaan program KFR {$permintaan->request_number} tersimpan.");
    }

    public function cancel(KfrProgramRequest $permintaan): RedirectResponse
    {
        try {
            $this->requests->cancel($permintaan);
        } catch (RegistrationException $e) {
            return back()->with('galat', $e->getMessage());
        }

        return back()->with('sukses', "Permintaan {$permintaan->request_number} dibatalkan.");
    }
}
