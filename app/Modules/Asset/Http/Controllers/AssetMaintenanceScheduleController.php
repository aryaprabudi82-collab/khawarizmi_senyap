<?php

namespace App\Modules\Asset\Http\Controllers;

use App\Modules\Asset\Models\Asset;
use App\Modules\Asset\Models\AssetLocation;
use App\Modules\Asset\Models\MaintenanceSchedule;
use App\Modules\Asset\Services\AssetException;
use App\Modules\Asset\Services\AssetMaintenanceScheduleService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** pemeliharaan_inventaris (+ pemeliharaan_gedung lewat location_id). */
class AssetMaintenanceScheduleController
{
    public function __construct(private readonly AssetMaintenanceScheduleService $schedules) {}

    public function index(): View
    {
        return view('asset::jadwal.index', [
            'jadwal' => MaintenanceSchedule::query()->with(['asset', 'location'])->orderBy('next_due_date')->get(),
            'aset' => Asset::query()->where('is_active', true)->orderBy('name')->get(),
            'lokasi' => AssetLocation::query()->where('is_active', true)->orderBy('name')->get(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'target_type' => ['required', 'in:aset,gedung'],
            'asset_id' => ['nullable', 'integer'],
            'location_id' => ['nullable', 'integer'],
            'title' => ['required', 'string', 'max:200'],
            'interval_months' => ['required', 'integer', 'min:1', 'max:120'],
        ], [], ['title' => 'judul', 'interval_months' => 'interval (bulan)']);

        $payload = [
            'title' => $data['title'],
            'interval_months' => $data['interval_months'],
            'asset_id' => $data['target_type'] === 'aset' ? ($data['asset_id'] ?? null) : null,
            'location_id' => $data['target_type'] === 'gedung' ? ($data['location_id'] ?? null) : null,
        ];

        try {
            $jadwal = $this->schedules->create($payload, $request->user()->id);
        } catch (AssetException $e) {
            return back()->withInput()->with('galat', $e->getMessage());
        }

        return back()->with('sukses', "Jadwal {$jadwal->schedule_number} dibuat.");
    }

    public function recordCompletion(Request $request, MaintenanceSchedule $jadwal): RedirectResponse
    {
        $data = $request->validate([
            'performed_at' => ['nullable', 'date'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);

        try {
            $this->schedules->recordCompletion($jadwal, $request->user(), $data['notes'] ?? null, $data['performed_at'] ?? null);
        } catch (AssetException $e) {
            return back()->with('galat', $e->getMessage());
        }

        return back()->with('sukses', "Pelaksanaan {$jadwal->schedule_number} dicatat, jadwal berikutnya {$jadwal->fresh()->next_due_date->format('d-m-Y')}.");
    }

    public function deactivate(MaintenanceSchedule $jadwal): RedirectResponse
    {
        $this->schedules->deactivate($jadwal);

        return back()->with('sukses', "Jadwal {$jadwal->schedule_number} dinonaktifkan.");
    }
}
