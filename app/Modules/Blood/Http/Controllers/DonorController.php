<?php

namespace App\Modules\Blood\Http\Controllers;

use App\Modules\Blood\Models\Donor;
use App\Modules\Blood\Services\DonorService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class DonorController
{
    public function __construct(private readonly DonorService $donors) {}

    public function index(): View
    {
        return view('blood::donor.index', [
            'pendonor' => Donor::query()->orderBy('name')->get(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:150'],
            'blood_type' => ['required', 'in:A,B,AB,O'],
            'rhesus' => ['required', 'in:+,-'],
            'birth_date' => ['nullable', 'date'],
            'sex' => ['required', 'in:L,P'],
            'phone' => ['nullable', 'string', 'max:40'],
            'address' => ['nullable', 'string', 'max:255'],
        ], [], ['name' => 'nama', 'blood_type' => 'golongan darah', 'rhesus' => 'rhesus', 'birth_date' => 'tanggal lahir', 'sex' => 'jenis kelamin']);

        $pendonor = $this->donors->register($data);

        return back()->with('sukses', "Pendonor {$pendonor->donor_number} — {$pendonor->name} terdaftar.");
    }

    public function update(Request $request, Donor $pendonor): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:150'],
            'phone' => ['nullable', 'string', 'max:40'],
            'address' => ['nullable', 'string', 'max:255'],
            'is_active' => ['nullable', 'boolean'],
        ], [], ['name' => 'nama']);

        $data['is_active'] = $request->boolean('is_active');

        $this->donors->update($pendonor, $data);

        return back()->with('sukses', "Pendonor {$pendonor->name} diperbarui.");
    }
}
