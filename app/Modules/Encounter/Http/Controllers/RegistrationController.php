<?php

namespace App\Modules\Encounter\Http\Controllers;

use App\Modules\Catalog\Models\Payer;
use App\Modules\Encounter\Models\Registration;
use App\Modules\Encounter\Services\RegistrationException;
use App\Modules\Encounter\Services\RegistrationService;
use App\Modules\Identity\Services\PatientRegistry;
use App\Modules\Organization\Services\OrganizationDirectory;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class RegistrationController
{
    public function __construct(
        private readonly RegistrationService $registrations,
        private readonly PatientRegistry $patients,
        private readonly OrganizationDirectory $organization,
    ) {}

    /**
     * Papan antrean.
     *
     * Satu kueri ke satu tabel: nama pasien, unit, dokter, dan penjamin sudah
     * tersalin ke baris registrasi, jadi tidak ada JOIN lintas konteks di sini.
     */
    public function index(Request $request): View
    {
        $tanggal = CarbonImmutable::parse($request->query('tanggal', now()->toDateString()))->startOfDay();
        $unitId = $request->integer('unit_id') ?: null;

        $antrean = Registration::query()
            ->whereDate('service_date', $tanggal->toDateString())
            ->when($unitId, fn ($q) => $q->where('unit_id', $unitId))
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')))
            ->orderBy('unit_name')
            ->orderBy('queue_number')
            ->paginate(50)
            ->withQueryString();

        $ringkasan = Registration::query()
            ->whereDate('service_date', $tanggal->toDateString())
            ->selectRaw('status, count(*) as jumlah')
            ->groupBy('status')
            ->pluck('jumlah', 'status');

        return view('encounter::registrations.index', [
            'antrean' => $antrean,
            'ringkasan' => $ringkasan,
            'tanggal' => $tanggal,
            'unitId' => $unitId,
            'units' => $this->organization->activeUnits(),
        ]);
    }

    public function create(Request $request): View
    {
        $tanggal = CarbonImmutable::parse($request->query('tanggal', now()->toDateString()))->startOfDay();
        $cari = trim((string) $request->query('cari', ''));

        return view('encounter::registrations.create', [
            'tanggal' => $tanggal,
            'cari' => $cari,
            'hasilCari' => $cari === '' ? collect() : $this->patients->search($cari),
            'pasien' => $request->filled('pasien_id')
                ? $this->patients->find($request->integer('pasien_id'))
                : null,
            'units' => $this->organization->activeUnits(),
            'praktisi' => $this->organization->practitionersServingOn($tanggal),
            'penjamin' => Payer::query()->where('is_active', true)->orderBy('name')->get(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'pasien_id' => ['required', 'integer'],
            'unit_id' => ['required', 'integer'],
            'penjamin_id' => ['required', 'integer'],
            'praktisi_id' => ['nullable', 'integer'],
            'tanggal' => ['required', 'date'],
            'nomor_rujukan' => ['nullable', 'string', 'max:60'],
            'nomor_kartu' => ['nullable', 'string', 'max:40'],
            'asal_faskes' => ['nullable', 'string', 'max:150'],
            'kode_faskes' => ['nullable', 'string', 'max:30'],
            'tanggal_rujukan' => ['nullable', 'date'],
            // Gerbang lapis kedua: field jenis_rawat hanya muncul di form untuk
            // pemegang permintaan_ranap, tapi validasi ini juga mencegah POST
            // langsung dari pengguna lain yang tidak punya izin itu.
            'jenis_rawat' => ['nullable', Rule::in($request->user()?->can('permintaan_ranap') ? ['ralan', 'ranap'] : ['ralan'])],
        ], [], [
            'pasien_id' => 'pasien',
            'unit_id' => 'unit layanan',
            'penjamin_id' => 'penjamin',
            'praktisi_id' => 'dokter',
            'tanggal' => 'tanggal pelayanan',
        ]);

        try {
            $registrasi = $this->registrations->register(
                patientId: $data['pasien_id'],
                unitId: $data['unit_id'],
                payerId: $data['penjamin_id'],
                practitionerId: $data['praktisi_id'] ?? null,
                serviceDate: CarbonImmutable::parse($data['tanggal']),
                extra: [
                    'referral_number' => $data['nomor_rujukan'] ?? null,
                    'membership_number' => $data['nomor_kartu'] ?? null,
                    'care_type' => $data['jenis_rawat'] ?? 'ralan',
                    'referring_facility_name' => $data['asal_faskes'] ?? null,
                    'referring_facility_code' => $data['kode_faskes'] ?? null,
                    'referral_date' => $data['tanggal_rujukan'] ?? null,
                ],
                actorId: $request->user()?->id,
            );
        } catch (RegistrationException $e) {
            return back()->withInput()->with('galat', $e->getMessage());
        }

        return redirect()
            ->route('registrasi.index', ['tanggal' => $registrasi->service_date->toDateString()])
            ->with('sukses', sprintf(
                'Registrasi %s berhasil. %s mendapat nomor antrean %d di %s.',
                $registrasi->registration_number,
                $registrasi->patient_name,
                $registrasi->queue_number,
                $registrasi->unit_name,
            ));
    }

    public function cancel(Request $request, Registration $registrasi): RedirectResponse
    {
        $data = $request->validate([
            'alasan' => ['required', 'string', 'min:5', 'max:255'],
        ], [], ['alasan' => 'alasan pembatalan']);

        try {
            $this->registrations->cancel($registrasi, $data['alasan'], $request->user()?->id);
        } catch (RegistrationException $e) {
            return back()->with('galat', $e->getMessage());
        }

        return back()->with('sukses', "Registrasi {$registrasi->registration_number} dibatalkan.");
    }
}
