<?php

namespace App\Modules\Envlab\Http\Controllers;

use App\Modules\Envlab\Models\Customer;
use App\Modules\Envlab\Models\SampleTest;
use App\Modules\Envlab\Models\SampleTestItem;
use App\Modules\Envlab\Models\SampleType;
use App\Modules\Envlab\Models\TestParameter;
use App\Modules\Envlab\Services\EnvlabException;
use App\Modules\Envlab\Services\SampleTestService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** Lihat catatan migrasi envlab.sample_tests untuk pemetaan access-flag ke peran per aksi di sini. */
class SampleTestController
{
    /**
     * Enam kode berbeda menggerbangi aksi berbeda di alur ini (lihat
     * catatan migrasi) — index/show harus tetap terbuka untuk siapa pun
     * yang punya SALAH SATU, supaya loket/penyelia/analis semua bisa
     * melihat antrean/detail sesuai tahap kerjanya masing-masing.
     * Middleware 'can:' statis tidak bisa mengekspresikan OR seperti ini,
     * jadi diperiksa imperatif di sini, pola sama dengan
     * OrderController::assertAccess()/BarcodeController::print().
     */
    private const RELEVANT_PERMISSIONS = [
        'permintaan_pengujian_sampel_lab_kesehatan_lingkungan',
        'penugasan_pengujian_sampel_lab_kesehatan_lingkungan',
        'hasil_pengujian_sampel_lab_kesehatan_lingkungan',
        'verifikasi_pengujian_sampel_lab_kesehatan_lingkungan',
        'validasi_pengujian_sampel_lab_kesehatan_lingkungan',
        'pembayaran_pengujian_sampel_lab_kesehatan_lingkungan',
    ];

    public function __construct(private readonly SampleTestService $tests) {}

    public function index(Request $request): View
    {
        $this->assertCanView($request);

        $daftar = SampleTest::query()
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')))
            ->orderByDesc('requested_at')
            ->paginate(50)
            ->withQueryString();

        return view('envlab::sample-tests.index', [
            'daftar' => $daftar,
            'status' => $request->string('status')->toString(),
            'pelanggan' => Customer::query()->where('is_active', true)->orderBy('name')->get(),
            'jenisSampel' => SampleType::query()->where('is_active', true)->orderBy('name')->get(),
            'parameter' => TestParameter::query()->where('is_active', true)->orderBy('name')->get(),
        ]);
    }

    public function show(Request $request, SampleTest $pengujian): View
    {
        $this->assertCanView($request);

        return view('envlab::sample-tests.show', [
            'pengujian' => $pengujian->load('items'),
        ]);
    }

    private function assertCanView(Request $request): void
    {
        $user = $request->user();
        $bolehLihat = $user !== null && collect(self::RELEVANT_PERMISSIONS)->contains(fn ($p) => $user->can($p));

        abort_unless($bolehLihat, 403);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'customer_id' => ['required', 'integer'],
            'sample_type_id' => ['required', 'integer'],
            'parameter_ids' => ['required', 'array', 'min:1'],
            'parameter_ids.*' => ['integer'],
            'sample_description' => ['nullable', 'string', 'max:255'],
        ], [], ['customer_id' => 'pelanggan', 'sample_type_id' => 'jenis sampel', 'parameter_ids' => 'parameter']);

        try {
            $permintaan = $this->tests->request(
                (int) $data['customer_id'],
                (int) $data['sample_type_id'],
                array_map('intval', $data['parameter_ids']),
                $data['sample_description'] ?? null,
                $request->user(),
            );
        } catch (EnvlabException $e) {
            return back()->with('galat', $e->getMessage())->withInput();
        }

        return redirect()->route('envlab-tests.show', $permintaan)
            ->with('sukses', "Permintaan {$permintaan->request_number} tersimpan.");
    }

    public function reject(Request $request, SampleTest $pengujian): RedirectResponse
    {
        $data = $request->validate([
            'rejection_reason' => ['required', 'string', 'min:5', 'max:255'],
        ], [], ['rejection_reason' => 'alasan penolakan']);

        try {
            $this->tests->reject($pengujian, $data['rejection_reason']);
        } catch (EnvlabException $e) {
            return back()->with('galat', $e->getMessage());
        }

        return back()->with('sukses', 'Sampel ditandai tidak dapat dilayani.');
    }

    public function accept(Request $request, SampleTest $pengujian): RedirectResponse
    {
        $data = $request->validate([
            'assigned_to_name' => ['required', 'string', 'max:150'],
        ], [], ['assigned_to_name' => 'nama analis']);

        try {
            $this->tests->accept($pengujian, $data['assigned_to_name'], $request->user());
        } catch (EnvlabException $e) {
            return back()->with('galat', $e->getMessage());
        }

        return back()->with('sukses', 'Sampel diterima dan ditugaskan.');
    }

    public function storeResult(Request $request, SampleTestItem $item): RedirectResponse
    {
        $data = $request->validate([
            'result_value' => ['nullable', 'numeric'],
            'result_text' => ['nullable', 'string', 'max:200'],
        ]);

        try {
            $this->tests->enterResult(
                $item,
                isset($data['result_value']) ? (float) $data['result_value'] : null,
                $data['result_text'] ?? null,
                $request->user(),
            );
        } catch (EnvlabException $e) {
            return back()->with('galat', $e->getMessage());
        }

        return back()->with('sukses', 'Hasil tersimpan.');
    }

    public function verify(Request $request, SampleTest $pengujian): RedirectResponse
    {
        try {
            $this->tests->verify($pengujian, $request->user());
        } catch (EnvlabException $e) {
            return back()->with('galat', $e->getMessage());
        }

        return back()->with('sukses', 'Hasil pengujian diverifikasi.');
    }

    public function validateResult(Request $request, SampleTest $pengujian): RedirectResponse
    {
        try {
            $this->tests->validateResult($pengujian, $request->user());
        } catch (EnvlabException $e) {
            return back()->with('galat', $e->getMessage());
        }

        return back()->with('sukses', 'Pengujian divalidasi dan selesai.');
    }

    public function markPaid(Request $request, SampleTest $pengujian): RedirectResponse
    {
        $data = $request->validate([
            'price' => ['required', 'numeric', 'min:0'],
        ], [], ['price' => 'jumlah pembayaran']);

        try {
            $this->tests->markPaid($pengujian, (float) $data['price']);
        } catch (EnvlabException $e) {
            return back()->with('galat', $e->getMessage());
        }

        return back()->with('sukses', 'Pembayaran tercatat lunas.');
    }
}
