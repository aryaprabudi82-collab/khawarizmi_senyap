<?php

namespace App\Modules\Quality\Http\Controllers;

use App\Modules\Quality\Models\PpiAudit;
use App\Modules\Quality\Services\OrganizationContext;
use App\Modules\Quality\Services\PpiAuditService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class PpiAuditController
{
    public function __construct(
        private readonly PpiAuditService $audits,
        private readonly OrganizationContext $organization,
    ) {}

    public function index(): View
    {
        return view('quality::ppi.index', [
            'audit' => PpiAudit::query()->latest('audited_on')->limit(50)->get(),
            'unit' => $this->organization->units(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'audit_type' => ['required', 'in:bundle-iadp,bundle-ido,bundle-isk,bundle-plabsi,bundle-vap,cuci-tangan-medis,fasilitas-apd,fasilitas-kebersihan-tangan,kamar-jenazah,kepatuhan-apd,pembuangan-benda-tajam,pembuangan-limbah,pembuangan-limbah-cair-infeksius,penanganan-darah,penempatan-pasien,pengelolaan-linen-kotor,sterilisasi-alat'],
            'audited_on' => ['required', 'date'],
            'unit_id' => ['nullable', 'integer'],
            'compliance_rate' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'findings' => ['required', 'string', 'max:2000'],
            'corrective_action' => ['nullable', 'string', 'max:2000'],
        ], [], [
            'audit_type' => 'jenis audit', 'audited_on' => 'tanggal audit', 'unit_id' => 'unit',
            'compliance_rate' => 'tingkat kepatuhan', 'findings' => 'temuan',
        ]);

        $this->audits->record($data, $request->user());

        return back()->with('sukses', 'Audit PPI tercatat.');
    }
}
