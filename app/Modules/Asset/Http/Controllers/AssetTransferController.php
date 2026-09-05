<?php

namespace App\Modules\Asset\Http\Controllers;

use App\Modules\Asset\Models\Asset;
use App\Modules\Asset\Models\AssetLocation;
use App\Modules\Asset\Models\AssetTransfer;
use App\Modules\Asset\Services\AssetException;
use App\Modules\Asset\Services\AssetTransferService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** inventaris_sirkulasi. */
class AssetTransferController
{
    public function __construct(private readonly AssetTransferService $transfers) {}

    public function index(): View
    {
        return view('asset::sirkulasi.index', [
            'aset' => Asset::query()->with('location')->where('is_active', true)->orderBy('name')->get(),
            'lokasi' => AssetLocation::query()->where('is_active', true)->orderBy('name')->get(),
            'mutasi' => AssetTransfer::query()->with(['asset', 'fromLocation', 'toLocation'])->latest('transferred_at')->limit(50)->get(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'asset_id' => ['required', 'integer'],
            'to_location_id' => ['required', 'integer'],
            'notes' => ['nullable', 'string', 'max:500'],
        ], [], ['asset_id' => 'aset', 'to_location_id' => 'lokasi tujuan']);

        $aset = Asset::query()->findOrFail($data['asset_id']);

        try {
            $mutasi = $this->transfers->transfer($aset, (int) $data['to_location_id'], $request->user(), $data['notes'] ?? null);
        } catch (AssetException $e) {
            return back()->withInput()->with('galat', $e->getMessage());
        }

        return back()->with('sukses', "Aset {$mutasi->asset->name} dipindahkan ke {$mutasi->toLocation->name}.");
    }
}
