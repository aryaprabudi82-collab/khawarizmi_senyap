<?php

namespace App\Modules\Asset\Http\Controllers;

use App\Modules\Asset\Models\CssdCirculation;
use App\Modules\Asset\Models\CssdItem;
use App\Modules\Asset\Services\AssetException;
use App\Modules\Asset\Services\CssdService;
use App\Modules\Asset\Services\OrganizationContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class CssdController
{
    public function __construct(
        private readonly CssdService $cssd,
        private readonly OrganizationContext $organization,
    ) {}

    public function index(): View
    {
        return view('asset::cssd.index', [
            'sirkulasi' => CssdCirculation::query()->with('cssdItem')->latest('received_at')->limit(50)->get(),
            'set' => CssdItem::query()->where('is_active', true)->orderBy('name')->get(),
            'unit' => $this->organization->units(),
        ]);
    }

    public function storeItem(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'max:20', Rule::unique(CssdItem::class, 'code')],
            'name' => ['required', 'string', 'max:150'],
        ], [], ['code' => 'kode', 'name' => 'nama set']);

        CssdItem::query()->create($data + ['is_active' => true]);

        return back()->with('sukses', "Set instrumen {$data['name']} ditambahkan.");
    }

    public function receive(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'cssd_item_id' => ['required', 'integer'],
            'unit_id' => ['required', 'integer'],
            'notes' => ['nullable', 'string', 'max:500'],
        ], [], ['cssd_item_id' => 'set instrumen', 'unit_id' => 'unit asal']);

        $set = CssdItem::query()->findOrFail($data['cssd_item_id']);
        $unit = $this->organization->find((int) $data['unit_id']);

        if ($unit === null) {
            return back()->withInput()->with('galat', 'Unit tidak ditemukan.');
        }

        $sirkulasi = $this->cssd->receive($set, $unit->id, $unit->name, $request->user(), $data['notes'] ?? null);

        return back()->with('sukses', "Sirkulasi {$sirkulasi->circulation_number} tercatat, status kotor.");
    }

    public function startProcessing(CssdCirculation $sirkulasi): RedirectResponse
    {
        try {
            $this->cssd->startProcessing($sirkulasi);
        } catch (AssetException $e) {
            return back()->with('galat', $e->getMessage());
        }

        return back()->with('sukses', "Sirkulasi {$sirkulasi->circulation_number} mulai diproses.");
    }

    public function markSterile(Request $request, CssdCirculation $sirkulasi): RedirectResponse
    {
        $data = $request->validate([
            'sterilization_method' => ['required', 'in:autoklaf-uap,etilen-oksida,plasma'],
        ], [], ['sterilization_method' => 'metode sterilisasi']);

        try {
            $this->cssd->markSterile($sirkulasi, $data['sterilization_method']);
        } catch (AssetException $e) {
            return back()->with('galat', $e->getMessage());
        }

        return back()->with('sukses', "Sirkulasi {$sirkulasi->circulation_number} steril.");
    }

    public function distribute(CssdCirculation $sirkulasi): RedirectResponse
    {
        try {
            $this->cssd->distribute($sirkulasi);
        } catch (AssetException $e) {
            return back()->with('galat', $e->getMessage());
        }

        return back()->with('sukses', "Sirkulasi {$sirkulasi->circulation_number} didistribusikan.");
    }
}
