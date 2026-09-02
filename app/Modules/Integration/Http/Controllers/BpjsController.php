<?php

namespace App\Modules\Integration\Http\Controllers;

use App\Modules\Integration\Models\BpjsSep;
use App\Modules\Integration\Services\Bpjs\EligibilityService;
use App\Modules\Integration\Services\Bpjs\SepService;
use App\Modules\Integration\Services\IdentityMappingService;
use App\Modules\Integration\Services\OrganizationContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use RuntimeException;

class BpjsController
{
    public function __construct(
        private readonly EligibilityService $eligibility,
        private readonly SepService $sep,
        private readonly IdentityMappingService $mappings,
        private readonly OrganizationContext $organization,
    ) {}

    public function index(): View
    {
        return view('integration::bpjs.index', [
            'sepTerbaru' => BpjsSep::query()->latest('requested_at')->limit(20)->get(),
            'unit' => $this->organization->units(),
            'pemetaanPoli' => $this->mappings->allFor('bpjs', 'poli', 'organization'),
        ]);
    }

    public function checkEligibility(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'no_kartu' => ['required', 'string', 'max:20'],
            'tanggal_pelayanan' => ['required', 'date'],
        ], [], ['no_kartu' => 'no. kartu', 'tanggal_pelayanan' => 'tanggal pelayanan']);

        $hasil = $this->eligibility->check($data['no_kartu'], $data['tanggal_pelayanan'], $request->user()->id);

        return back()->with($hasil->is_eligible === true ? 'sukses' : 'galat', $hasil->is_eligible === true
            ? "Peserta {$hasil->participant_name} aktif."
            : ($hasil->error_message ?? 'Peserta tidak eligible.'));
    }

    public function storeSep(Request $request, int $registrasi): RedirectResponse
    {
        $data = $request->validate([
            'no_kartu' => ['required', 'string', 'max:20'],
            'no_rujukan' => ['nullable', 'string', 'max:50'],
        ], [], ['no_kartu' => 'no. kartu', 'no_rujukan' => 'no. rujukan']);

        try {
            $sep = $this->sep->create($registrasi, $data['no_kartu'], $data['no_rujukan'] ?? null, $request->user()->id);
        } catch (RuntimeException $e) {
            return back()->withInput()->with('galat', $e->getMessage());
        }

        return back()->with('sukses', "SEP {$sep->sep_number} berhasil diterbitkan.");
    }

    public function cancelSep(Request $request, BpjsSep $sep): RedirectResponse
    {
        $data = $request->validate(['alasan' => ['required', 'string', 'max:255']], [], ['alasan' => 'alasan']);

        try {
            $this->sep->cancel($sep, $data['alasan']);
        } catch (RuntimeException $e) {
            return back()->with('galat', $e->getMessage());
        }

        return back()->with('sukses', "SEP {$sep->sep_number} dibatalkan.");
    }

    public function mapPoli(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'unit_id' => ['required', 'integer'],
            'kode_poli_bpjs' => ['required', 'string', 'max:10'],
        ], [], ['unit_id' => 'unit', 'kode_poli_bpjs' => 'kode poli BPJS']);

        $this->mappings->setManually('bpjs', 'poli', 'organization', $data['unit_id'], $data['kode_poli_bpjs'], $request->user()->id);

        return back()->with('sukses', 'Pemetaan poli BPJS disimpan.');
    }
}
