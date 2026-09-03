<?php

namespace App\Modules\Envlab\Http\Controllers;

use App\Modules\Envlab\Models\Customer;
use App\Modules\Envlab\Models\QualityStandard;
use App\Modules\Envlab\Models\SampleType;
use App\Modules\Envlab\Models\TestParameter;
use App\Modules\Envlab\Services\EnvlabException;
use App\Modules\Envlab\Services\EnvlabMasterDataService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class MasterDataController
{
    public function __construct(private readonly EnvlabMasterDataService $admin) {}

    public function index(): View
    {
        return view('envlab::master.index', [
            'pelanggan' => Customer::query()->orderBy('name')->get(),
            'jenisSampel' => SampleType::query()->orderBy('name')->get(),
            'parameter' => TestParameter::query()->orderBy('name')->get(),
            'bakuMutu' => QualityStandard::query()->with(['sampleType', 'parameter'])->get()
                ->sortBy(fn ($b) => $b->sampleType?->name . $b->parameter?->name),
        ]);
    }

    public function storeCustomer(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'max:20', Rule::unique(Customer::class, 'code')],
            'name' => ['required', 'string', 'max:150'],
            'kind' => ['required', 'in:internal,eksternal'],
            'address' => ['nullable', 'string', 'max:255'],
            'contact_person' => ['nullable', 'string', 'max:100'],
            'contact_phone' => ['nullable', 'string', 'max:30'],
        ], [], ['code' => 'kode', 'name' => 'nama', 'kind' => 'jenis pelanggan']);

        $this->admin->createCustomer($data + ['is_active' => true]);

        return back()->with('sukses', "Pelanggan {$data['name']} ditambahkan.");
    }

    public function storeSampleType(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'max:20', Rule::unique(SampleType::class, 'code')],
            'name' => ['required', 'string', 'max:150'],
            'category' => ['required', 'in:' . implode(',', SampleType::CATEGORIES)],
            'regulatory_reference' => ['nullable', 'string', 'max:200'],
        ], [], ['code' => 'kode', 'name' => 'nama', 'category' => 'kategori']);

        $this->admin->createSampleType($data + ['is_active' => true]);

        return back()->with('sukses', "Jenis sampel {$data['name']} ditambahkan.");
    }

    public function storeParameter(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'max:20', Rule::unique(TestParameter::class, 'code')],
            'name' => ['required', 'string', 'max:150'],
            'unit' => ['nullable', 'string', 'max:30'],
            'test_method' => ['nullable', 'string', 'max:150'],
        ], [], ['code' => 'kode', 'name' => 'nama', 'unit' => 'satuan']);

        $this->admin->createParameter($data + ['is_active' => true]);

        return back()->with('sukses', "Parameter {$data['name']} ditambahkan.");
    }

    public function storeQualityStandard(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'sample_type_id' => ['required', 'integer', Rule::exists(SampleType::class, 'id')],
            'parameter_id' => ['required', 'integer', Rule::exists(TestParameter::class, 'id')],
            'min_value' => ['nullable', 'numeric'],
            'max_value' => ['nullable', 'numeric'],
            'qualitative_standard' => ['nullable', 'string', 'max:100'],
            'regulatory_reference' => ['nullable', 'string', 'max:200'],
        ], [], [
            'sample_type_id' => 'jenis sampel', 'parameter_id' => 'parameter',
            'min_value' => 'nilai minimal', 'max_value' => 'nilai maksimal',
        ]);

        $sampleTypeId = (int) $data['sample_type_id'];
        $parameterId = (int) $data['parameter_id'];
        unset($data['sample_type_id'], $data['parameter_id']);

        try {
            $this->admin->setQualityStandard($sampleTypeId, $parameterId, $data);
        } catch (EnvlabException $e) {
            return back()->with('galat', $e->getMessage())->withInput();
        }

        return back()->with('sukses', 'Baku mutu tersimpan.');
    }
}
