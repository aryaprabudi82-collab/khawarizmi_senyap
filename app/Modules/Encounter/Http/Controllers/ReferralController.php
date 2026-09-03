<?php

namespace App\Modules\Encounter\Http\Controllers;

use App\Modules\Encounter\Models\OutgoingReferral;
use App\Modules\Encounter\Models\Registration;
use App\Modules\Encounter\Services\RegistrationException;
use App\Modules\Encounter\Services\ReferralService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** rujukan_keluar — lihat catatan migrasi encounter untuk kenapa terpisah dari registrasi biasa. */
class ReferralController
{
    public function __construct(private readonly ReferralService $referrals) {}

    public function index(): View
    {
        return view('encounter::referrals.index', [
            'rujukan' => OutgoingReferral::query()->latest('referred_at')->limit(50)->get(),
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
            'destination_facility_name' => ['required', 'string', 'max:150'],
            'destination_facility_code' => ['nullable', 'string', 'max:30'],
            'reason' => ['required', 'string', 'max:2000'],
            'diagnosis' => ['nullable', 'string', 'max:1000'],
        ], [], [
            'registration_id' => 'kunjungan', 'destination_facility_name' => 'faskes tujuan',
            'destination_facility_code' => 'kode faskes', 'reason' => 'alasan rujukan',
        ]);

        $registrasi = Registration::query()->find($data['registration_id']);

        if ($registrasi === null) {
            return back()->withInput()->with('galat', 'Kunjungan tidak ditemukan.');
        }

        unset($data['registration_id']);

        $rujukan = $this->referrals->issue($registrasi, $data, $request->user());

        return back()->with('sukses', "Rujukan {$rujukan->referral_number} diterbitkan.");
    }

    public function cancel(OutgoingReferral $rujukan): RedirectResponse
    {
        try {
            $this->referrals->cancel($rujukan);
        } catch (RegistrationException $e) {
            return back()->with('galat', $e->getMessage());
        }

        return back()->with('sukses', "Rujukan {$rujukan->referral_number} dibatalkan.");
    }

    public function print(OutgoingReferral $rujukan): View
    {
        return view('encounter::referrals.cetak', ['rujukan' => $rujukan]);
    }
}
