<?php

namespace App\Modules\Encounter\Http\Controllers;

use App\Modules\Encounter\Models\CorporateMcuBooking;
use App\Modules\Encounter\Services\CorporateMcuBookingService;
use App\Modules\Encounter\Services\RegistrationException;
use App\Modules\Organization\Services\OrganizationDirectory;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class CorporateMcuBookingController
{
    public function __construct(
        private readonly CorporateMcuBookingService $bookings,
        private readonly OrganizationDirectory $organization,
    ) {}

    public function index(Request $request): View
    {
        $daftar = CorporateMcuBooking::query()
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')))
            ->orderByDesc('scheduled_date')
            ->paginate(50)
            ->withQueryString();

        return view('encounter::corporate-mcu.index', [
            'daftar' => $daftar,
            'status' => $request->string('status')->toString(),
            'units' => $this->organization->activeUnits(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'company_name' => ['required', 'string', 'max:150'],
            'contact_person' => ['nullable', 'string', 'max:100'],
            'contact_phone' => ['nullable', 'string', 'max:30'],
            'unit_id' => ['nullable', 'integer'],
            'scheduled_date' => ['required', 'date'],
            'employee_count' => ['required', 'integer', 'min:1'],
            'package_description' => ['nullable', 'string', 'max:500'],
            'note' => ['nullable', 'string', 'max:255'],
        ], [], [
            'company_name' => 'nama perusahaan', 'scheduled_date' => 'tanggal pelaksanaan',
            'employee_count' => 'jumlah karyawan',
        ]);

        if (! empty($data['unit_id'])) {
            $unit = $this->organization->findUnit((int) $data['unit_id']);
            $data['unit_name'] = $unit?->name;
        }

        try {
            $booking = $this->bookings->book($data, $request->user());
        } catch (RegistrationException $e) {
            return back()->with('galat', $e->getMessage())->withInput();
        }

        return redirect()->route('mcu-perusahaan.index')
            ->with('sukses', "Booking MCU {$booking->booking_number} untuk {$booking->company_name} tersimpan.");
    }

    public function complete(CorporateMcuBooking $booking): RedirectResponse
    {
        try {
            $this->bookings->complete($booking);
        } catch (RegistrationException $e) {
            return back()->with('galat', $e->getMessage());
        }

        return back()->with('sukses', 'Booking MCU ditandai selesai.');
    }

    public function cancel(CorporateMcuBooking $booking): RedirectResponse
    {
        try {
            $this->bookings->cancel($booking);
        } catch (RegistrationException $e) {
            return back()->with('galat', $e->getMessage());
        }

        return back()->with('sukses', 'Booking MCU dibatalkan.');
    }
}
