<?php

namespace App\Modules\Asset\Http\Controllers;

use App\Modules\Asset\Models\AssetCategory;
use App\Modules\Asset\Models\Requisition;
use App\Modules\Asset\Services\AssetException;
use App\Modules\Asset\Services\AssetRequisitionRecapService;
use App\Modules\Asset\Services\AssetRequisitionService;
use App\Modules\Asset\Services\OrganizationContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** pengajuan_asetinventaris (+ rekap_pengajuan_aset_departemen sebagai tab). */
class AssetRequisitionController
{
    public function __construct(
        private readonly AssetRequisitionService $requisitions,
        private readonly AssetRequisitionRecapService $recap,
        private readonly OrganizationContext $organization,
    ) {}

    public function index(Request $request): View
    {
        $dari = $request->query('dari', now()->startOfMonth()->toDateString());
        $sampai = $request->query('sampai', now()->toDateString());

        return view('asset::pengajuan.index', [
            'pengajuan' => Requisition::query()->with('items.category')->latest('created_at')->limit(50)->get(),
            'unit' => $this->organization->units(),
            'kategori' => AssetCategory::query()->orderBy('name')->get(),
            'dari' => $dari,
            'sampai' => $sampai,
            'rekapDepartemen' => $this->recap->perDepartemen($dari, $sampai),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'unit_id' => ['required', 'integer'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'item_name' => ['required', 'array', 'min:1'],
            'item_name.*' => ['nullable', 'string', 'max:200'],
            'category_id' => ['required', 'array'],
            'category_id.*' => ['nullable', 'integer'],
            'quantity' => ['required', 'array'],
            'quantity.*' => ['nullable', 'numeric', 'min:0'],
        ], [], ['unit_id' => 'unit']);

        $unit = $this->organization->find((int) $data['unit_id']);

        if ($unit === null) {
            return back()->withInput()->with('galat', 'Unit tidak ditemukan.');
        }

        $items = [];
        foreach ($data['item_name'] as $i => $namaBarang) {
            $qty = (float) ($data['quantity'][$i] ?? 0);
            if ($qty > 0 && trim((string) $namaBarang) !== '') {
                $items[] = ['item_name' => $namaBarang, 'category_id' => $data['category_id'][$i] ?? null, 'quantity' => $qty];
            }
        }

        try {
            $pengajuan = $this->requisitions->request((int) $data['unit_id'], $unit->name, $items, $data['notes'] ?? null, $request->user()->id);
        } catch (AssetException $e) {
            return back()->withInput()->with('galat', $e->getMessage());
        }

        return back()->with('sukses', "Pengajuan {$pengajuan->requisition_number} diajukan.");
    }

    public function approve(Request $request, Requisition $pengajuan): RedirectResponse
    {
        try {
            $this->requisitions->approve($pengajuan, $request->user()->id);
        } catch (AssetException $e) {
            return back()->with('galat', $e->getMessage());
        }

        return back()->with('sukses', "Pengajuan {$pengajuan->requisition_number} disetujui.");
    }

    public function reject(Request $request, Requisition $pengajuan): RedirectResponse
    {
        $data = $request->validate(['rejection_reason' => ['required', 'string', 'max:255']], [], ['rejection_reason' => 'alasan']);

        try {
            $this->requisitions->reject($pengajuan, $request->user()->id, $data['rejection_reason']);
        } catch (AssetException $e) {
            return back()->with('galat', $e->getMessage());
        }

        return back()->with('sukses', "Pengajuan {$pengajuan->requisition_number} ditolak.");
    }
}
