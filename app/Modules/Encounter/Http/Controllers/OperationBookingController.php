<?php

namespace App\Modules\Encounter\Http\Controllers;

use App\Modules\Encounter\Models\OperationBooking;
use App\Modules\Encounter\Models\Registration;
use App\Modules\Encounter\Services\OperationBookingService;
use App\Modules\Encounter\Services\RegistrationException;
use App\Modules\Organization\Services\OrganizationDirectory;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class OperationBookingController
{
    public function __construct(
        private readonly OperationBookingService $bookings,
        private readonly OrganizationDirectory $organization,
    ) {}

    public function index(Request $request): View
    {
        $q = trim((string) $request->query('q', ''));

        $daftar = OperationBooking::query()
            ->when($request->filled('status'), fn ($query) => $query->where('status', $request->string('status')))
            ->orderByDesc('scheduled_at')
            ->paginate(50)
            ->withQueryString();

        $hasilPencarian = $q === '' ? collect() : Registration::query()
            ->where('status', '!=', Registration::STATUS_BATAL)
            ->where(function ($query) use ($q) {
                $query->where('registration_number', 'ilike', '%'.$q.'%')
                    ->orWhere('patient_mrn', 'ilike', '%'.$q.'%')
                    ->orWhere('patient_name', 'ilike', '%'.$q.'%');
            })
            ->orderByDesc('registered_at')
            ->limit(20)
            ->get();

        return view('encounter::operation-bookings.index', [
            'daftar' => $daftar,
            'q' => $q,
            'hasilPencarian' => $hasilPencarian,
            'status' => $request->string('status')->toString(),
            'praktisi' => $this->organization->activePractitioners(),
            'ruangOperasi' => $this->organization->activeOperatingRooms(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'registration_id' => ['required', 'integer'],
            'procedure_name' => ['required', 'string', 'max:200'],
            'surgeon_id' => ['nullable', 'integer'],
            'operating_room' => ['nullable', 'string', 'max:50'],
            'scheduled_at' => ['required', 'date'],
            'note' => ['nullable', 'string', 'max:255'],
        ], [], [
            'registration_id' => 'kunjungan', 'procedure_name' => 'nama tindakan operasi', 'scheduled_at' => 'jadwal',
        ]);

        /*
         * Ruang operasi dipilih dari master, tidak diketik — dan ini tempat
         * KEDUA yang dulu mengetiknya bebas. Ruang yang sama diketik dua
         * kali oleh dua orang berbeda (penjadwal di sini, operator di
         * laporan operasi), lalu laporan RL mengelompokkan berdasarkan
         * teksnya dan memecah satu ruang jadi dua baris.
         */
        if (! $this->organization->operatingRoomIsUsable($data['operating_room'] ?? null)) {
            return back()->withInput()->with('galat',
                'Ruang operasi harus dipilih dari master ruang operasi yang aktif.');
        }

        $registrationId = (int) $data['registration_id'];
        unset($data['registration_id']);

        if (! empty($data['surgeon_id'])) {
            $operator = $this->organization->findPractitioner((int) $data['surgeon_id']);
            $data['surgeon_name'] = $operator?->name;
        }

        try {
            $booking = $this->bookings->book($registrationId, $data, $request->user());
        } catch (RegistrationException $e) {
            return back()->with('galat', $e->getMessage())->withInput();
        }

        return redirect()->route('booking-operasi.index')
            ->with('sukses', "Jadwal operasi {$booking->booking_number} untuk {$booking->patient_name} tersimpan.");
    }

    public function complete(OperationBooking $booking): RedirectResponse
    {
        try {
            $this->bookings->complete($booking);
        } catch (RegistrationException $e) {
            return back()->with('galat', $e->getMessage());
        }

        return back()->with('sukses', 'Jadwal operasi ditandai selesai.');
    }

    public function cancel(OperationBooking $booking): RedirectResponse
    {
        try {
            $this->bookings->cancel($booking);
        } catch (RegistrationException $e) {
            return back()->with('galat', $e->getMessage());
        }

        return back()->with('sukses', 'Jadwal operasi dibatalkan.');
    }
}
