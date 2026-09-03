<?php

namespace App\Modules\Asset\Http\Controllers;

use App\Modules\Asset\Models\Asset;
use App\Modules\Asset\Models\MaintenanceRequest;
use App\Modules\Asset\Services\AssetException;
use App\Modules\Asset\Services\MaintenanceService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class MaintenanceController
{
    public function __construct(private readonly MaintenanceService $maintenance) {}

    public function index(): View
    {
        return view('asset::pemeliharaan.index', [
            'permintaan' => MaintenanceRequest::query()->with('asset')->latest('created_at')->limit(50)->get(),
            'aset' => Asset::query()->where('is_active', true)->orderBy('name')->get(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'asset_id' => ['required', 'integer'],
            'description' => ['required', 'string', 'max:1000'],
        ], [], ['asset_id' => 'aset', 'description' => 'keluhan']);

        $aset = Asset::query()->findOrFail($data['asset_id']);

        try {
            $permintaan = $this->maintenance->report($aset, $data['description'], $request->user()->id);
        } catch (AssetException $e) {
            return back()->withInput()->with('galat', $e->getMessage());
        }

        return back()->with('sukses', "Permintaan {$permintaan->request_number} tercatat.");
    }

    public function start(Request $request, MaintenanceRequest $permintaan): RedirectResponse
    {
        try {
            $this->maintenance->start($permintaan, $request->user());
        } catch (AssetException $e) {
            return back()->with('galat', $e->getMessage());
        }

        return back()->with('sukses', "Permintaan {$permintaan->request_number} mulai dikerjakan.");
    }

    public function complete(Request $request, MaintenanceRequest $permintaan): RedirectResponse
    {
        $data = $request->validate([
            'resolution_notes' => ['required', 'string', 'max:1000'],
            'condition' => ['required', 'in:baik,rusak-ringan,rusak-berat'],
        ], [], ['resolution_notes' => 'catatan penyelesaian', 'condition' => 'kondisi akhir']);

        try {
            $this->maintenance->complete($permintaan, $data['resolution_notes'], $data['condition']);
        } catch (AssetException $e) {
            return back()->with('galat', $e->getMessage());
        }

        return back()->with('sukses', "Permintaan {$permintaan->request_number} selesai.");
    }

    public function reject(Request $request, MaintenanceRequest $permintaan): RedirectResponse
    {
        $data = $request->validate(['rejection_reason' => ['required', 'string', 'max:255']], [], ['rejection_reason' => 'alasan']);

        try {
            $this->maintenance->reject($permintaan, $data['rejection_reason']);
        } catch (AssetException $e) {
            return back()->with('galat', $e->getMessage());
        }

        return back()->with('sukses', "Permintaan {$permintaan->request_number} ditolak.");
    }
}
