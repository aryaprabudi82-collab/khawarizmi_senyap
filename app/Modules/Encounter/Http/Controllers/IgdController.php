<?php

namespace App\Modules\Encounter\Http\Controllers;

use App\Modules\Catalog\Models\Payer;
use App\Modules\Encounter\Models\IgdTriage;
use App\Modules\Encounter\Models\Registration;
use App\Modules\Encounter\Services\IgdService;
use App\Modules\Encounter\Services\RegistrationException;
use App\Modules\Identity\Services\PatientRegistry;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/** igd — lihat catatan migrasi encounter. */
class IgdController
{
    public function __construct(
        private readonly IgdService $igd,
        private readonly PatientRegistry $patients,
    ) {}

    public function index(Request $request): View
    {
        $pasienIgd = Registration::query()
            ->where('care_type', 'igd')
            ->whereDate('service_date', now()->toDateString())
            ->where('status', '<>', Registration::STATUS_BATAL)
            ->get();

        $triase = IgdTriage::query()
            ->whereIn('registration_id', $pasienIgd->pluck('id'))
            ->get()
            ->keyBy('registration_id');

        // Belum ditriase dulu (paling mendesak diperiksa), lalu urut warna
        // merah-kuning-hijau-hitam, baru berdasar waktu datang.
        $terurut = $pasienIgd->sortBy(function (Registration $r) use ($triase) {
            $level = $triase->get($r->id)?->triage_level;

            return [$level === null ? 0 : 1, IgdTriage::PRIORITY[$level] ?? 99, $r->registered_at];
        })->values();

        $cari = trim((string) $request->query('cari', ''));

        return view('encounter::igd.index', [
            'pasienIgd' => $terurut,
            'triase' => $triase,
            'cari' => $cari,
            'hasilCari' => $cari === '' ? collect() : $this->patients->search($cari),
            'penjamin' => Payer::query()->where('is_active', true)->orderBy('name')->get(),
        ]);
    }

    public function register(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'pasien_id' => ['required', 'integer'],
            'penjamin_id' => ['required', 'integer'],
        ], [], ['pasien_id' => 'pasien', 'penjamin_id' => 'penjamin']);

        try {
            $registrasi = $this->igd->register($data['pasien_id'], $data['penjamin_id'], actorId: $request->user()?->id);
        } catch (RegistrationException $e) {
            return back()->withInput()->with('galat', $e->getMessage());
        }

        return redirect()->route('igd.index')
            ->with('sukses', "{$registrasi->patient_name} terdaftar di IGD. Segera lakukan triase.");
    }

    public function triage(Request $request, Registration $registrasi): RedirectResponse
    {
        $data = $request->validate([
            'triage_level' => ['required', Rule::in(IgdTriage::LEVELS)],
            'chief_complaint' => ['required', 'string', 'max:1000'],
        ], [], ['triage_level' => 'level triase', 'chief_complaint' => 'keluhan utama']);

        $this->igd->triage($registrasi, $data['triage_level'], $data['chief_complaint'], $request->user());

        return back()->with('sukses', "Triase {$registrasi->patient_name} tercatat.");
    }
}
