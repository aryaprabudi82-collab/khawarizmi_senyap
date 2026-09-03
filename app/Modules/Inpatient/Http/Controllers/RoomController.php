<?php

namespace App\Modules\Inpatient\Http\Controllers;

use App\Modules\Inpatient\Models\Bed;
use App\Modules\Inpatient\Models\Room;
use App\Modules\Inpatient\Services\InpatientException;
use App\Modules\Inpatient\Services\OrganizationContext;
use App\Modules\Inpatient\Services\RoomService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class RoomController
{
    public function __construct(
        private readonly RoomService $rooms,
        private readonly OrganizationContext $organization,
    ) {}

    public function index(): View
    {
        return view('inpatient::kamar.index', [
            'kamar' => Room::query()->with('beds')->orderBy('room_number')->get(),
            'unit' => $this->organization->units(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'room_number' => ['required', 'string', 'max:20', Rule::unique(Room::class, 'room_number')],
            'room_class' => ['required', Rule::in(Room::CLASSES)],
            'unit_id' => ['nullable', 'integer'],
            'daily_rate' => ['nullable', 'numeric', 'min:0'],
        ], [], ['room_number' => 'nomor kamar', 'room_class' => 'kelas kamar', 'unit_id' => 'unit/bangsal', 'daily_rate' => 'tarif/hari']);

        if (! empty($data['unit_id'])) {
            $unit = $this->organization->units()->firstWhere('id', $data['unit_id']);
            $data['unit_name'] = $unit->name ?? null;
        }

        $kamar = $this->rooms->createRoom($data);

        return back()->with('sukses', "Kamar {$kamar->room_number} ditambahkan.");
    }

    public function update(Request $request, Room $kamar): RedirectResponse
    {
        $data = $request->validate([
            'room_class' => ['required', Rule::in(Room::CLASSES)],
            'daily_rate' => ['nullable', 'numeric', 'min:0'],
            'is_active' => ['nullable', 'boolean'],
        ], [], ['room_class' => 'kelas kamar', 'daily_rate' => 'tarif/hari']);

        $data['is_active'] = $request->boolean('is_active');

        $this->rooms->updateRoom($kamar, $data);

        return back()->with('sukses', "Kamar {$kamar->room_number} diperbarui.");
    }

    public function addBed(Request $request, Room $kamar): RedirectResponse
    {
        $data = $request->validate([
            'bed_number' => ['required', 'string', 'max:10'],
        ], [], ['bed_number' => 'nomor bed']);

        $this->rooms->addBed($kamar, $data['bed_number']);

        return back()->with('sukses', "Bed {$data['bed_number']} ditambahkan ke kamar {$kamar->room_number}.");
    }

    public function markClean(Bed $bed): RedirectResponse
    {
        try {
            $this->rooms->markClean($bed);
        } catch (InpatientException $e) {
            return back()->with('galat', $e->getMessage());
        }

        return back()->with('sukses', "Bed {$bed->bed_number} siap dipakai kembali.");
    }

    public function deactivateBed(Bed $bed): RedirectResponse
    {
        try {
            $this->rooms->deactivate($bed);
        } catch (InpatientException $e) {
            return back()->with('galat', $e->getMessage());
        }

        return back()->with('sukses', "Bed {$bed->bed_number} dinonaktifkan.");
    }

    public function reactivateBed(Bed $bed): RedirectResponse
    {
        try {
            $this->rooms->reactivate($bed);
        } catch (InpatientException $e) {
            return back()->with('galat', $e->getMessage());
        }

        return back()->with('sukses', "Bed {$bed->bed_number} diaktifkan kembali.");
    }
}
