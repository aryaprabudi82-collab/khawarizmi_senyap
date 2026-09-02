<?php

namespace App\Modules\Catalog\Http\Controllers;

use App\Modules\Catalog\Models\Payer;
use App\Modules\Catalog\Models\Service;
use App\Modules\Catalog\Models\Tariff;
use App\Modules\Catalog\Services\TariffService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class MasterDataController
{
    public function __construct(private readonly TariffService $tariffs) {}

    public function index(): View
    {
        return view('catalog::master.index', [
            'penjamin' => Payer::query()->orderBy('name')->get(),
            'layanan' => Service::query()->orderBy('category')->orderBy('name')->get(),
            'tarif' => Tariff::query()
                ->with(['service', 'payer'])
                ->whereNull('valid_until')
                ->orderBy('service_id')
                ->get(),
        ]);
    }

    public function storePayer(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'max:20', Rule::unique(Payer::class, 'code')],
            'name' => ['required', 'string', 'max:150'],
            'kind' => ['required', 'in:umum,bpjs,asuransi,perusahaan'],
            'company_name' => ['nullable', 'string', 'max:150'],
            'phone' => ['nullable', 'string', 'max:40'],
        ], [], ['code' => 'kode', 'name' => 'nama', 'kind' => 'jenis']);

        $this->tariffs->createPayer($data + ['is_active' => true]);

        return back()->with('sukses', "Penjamin {$data['name']} ditambahkan.");
    }

    public function updatePayer(Request $request, Payer $penjamin): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:150'],
            'kind' => ['required', 'in:umum,bpjs,asuransi,perusahaan'],
            'company_name' => ['nullable', 'string', 'max:150'],
            'phone' => ['nullable', 'string', 'max:40'],
            'is_active' => ['nullable', 'boolean'],
        ], [], ['name' => 'nama', 'kind' => 'jenis']);

        $data['is_active'] = $request->boolean('is_active');

        $this->tariffs->updatePayer($penjamin, $data);

        return back()->with('sukses', "Penjamin {$penjamin->name} diperbarui.");
    }

    public function storeService(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'max:30', Rule::unique(Service::class, 'code')],
            'name' => ['required', 'string', 'max:200'],
            'category' => ['required', 'in:registrasi,konsultasi,tindakan,penunjang'],
        ], [], ['code' => 'kode', 'name' => 'nama', 'category' => 'kategori']);

        $this->tariffs->createService($data + ['is_active' => true]);

        return back()->with('sukses', "Layanan {$data['name']} ditambahkan.");
    }

    public function updateService(Request $request, Service $layanan): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:200'],
            'category' => ['required', 'in:registrasi,konsultasi,tindakan,penunjang'],
            'is_active' => ['nullable', 'boolean'],
        ], [], ['name' => 'nama', 'category' => 'kategori']);

        $data['is_active'] = $request->boolean('is_active');

        $this->tariffs->updateService($layanan, $data);

        return back()->with('sukses', "Layanan {$layanan->name} diperbarui.");
    }

    public function storeTariff(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'service_id' => ['required', 'integer', Rule::exists(Service::class, 'id')],
            'payer_id' => ['required', 'integer', Rule::exists(Payer::class, 'id')],
            'care_class' => ['required', 'string', 'max:20'],
            'amount' => ['required', 'numeric', 'min:0'],
            'amount_returning' => ['nullable', 'numeric', 'min:0'],
            'valid_from' => ['required', 'date'],
        ], [], [
            'service_id' => 'layanan', 'payer_id' => 'penjamin', 'care_class' => 'kelas',
            'amount' => 'tarif', 'amount_returning' => 'tarif pasien lama', 'valid_from' => 'berlaku mulai',
        ]);

        try {
            $this->tariffs->setRate(
                serviceId: $data['service_id'],
                payerId: $data['payer_id'],
                careClass: $data['care_class'],
                amount: (float) $data['amount'],
                amountReturning: isset($data['amount_returning']) ? (float) $data['amount_returning'] : null,
                validFrom: $data['valid_from'],
            );
        } catch (\RuntimeException $e) {
            return back()->withInput()->with('galat', $e->getMessage());
        }

        return back()->with('sukses', 'Tarif tersimpan dan berlaku mulai ' . date('d-m-Y', strtotime($data['valid_from'])) . '.');
    }
}
