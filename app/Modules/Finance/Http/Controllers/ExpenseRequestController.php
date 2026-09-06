<?php

namespace App\Modules\Finance\Http\Controllers;

use App\Modules\Finance\Models\CashCategory;
use App\Modules\Finance\Models\ExpenseRequest;
use App\Modules\Finance\Services\ExpenseRequestService;
use App\Modules\Finance\Services\FinanceException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Pengajuan & persetujuan biaya (domain K item F).
 *
 * Tiga tahap, tiga gerbang berbeda: mengajukan, menyetujui, dan
 * memvalidasi persetujuan. Pemisahannya ditegakkan dua kali — di gerbang
 * peran DAN di service — karena di rumah sakit kecil satu orang lazim
 * memegang beberapa peran sekaligus, dan pemisahan yang cuma ada di
 * gerbang akan runtuh persis di situ.
 */
class ExpenseRequestController
{
    public function __construct(private readonly ExpenseRequestService $pengajuan) {}

    public function index(Request $request): View
    {
        $status = $request->query('status') ?: null;
        $unit = $request->query('unit') ?: null;
        $dari = $request->query('dari', now()->startOfMonth()->toDateString());
        $sampai = $request->query('sampai', now()->toDateString());

        return view('finance::pengajuan-biaya.index', [
            'status' => $status,
            'unit' => $unit,
            'dari' => $dari,
            'sampai' => $sampai,

            'daftar' => $this->pengajuan->requests($status, $unit, $dari, $sampai),
            'menunggu' => $this->pengajuan->pending(),
            'perUnit' => $this->pengajuan->recapByUnit($dari, $sampai),
            'perPos' => $this->pengajuan->recapByCategory($dari, $sampai),
            'pos' => CashCategory::query()->where('direction', CashCategory::KELUAR)->where('is_active', true)->orderBy('name')->get(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'unit_name' => ['required', 'string', 'max:120'],
            'requested_on' => ['required', 'date'],
            'purpose' => ['required', 'string', 'max:200'],
            'justification' => ['nullable', 'string'],
            'requested_amount' => ['required', 'numeric', 'gt:0'],
            'category_id' => ['nullable', 'integer'],
        ]);

        try {
            $this->pengajuan->submit($data, $request->user()?->id, $request->user()?->name);
        } catch (FinanceException $e) {
            return back()->withInput()->withErrors(['requested_amount' => $e->getMessage()]);
        }

        return back()->with('status', 'Pengajuan biaya tercatat, menunggu persetujuan.');
    }

    public function approve(Request $request, ExpenseRequest $pengajuan): RedirectResponse
    {
        $data = $request->validate([
            'approved_amount' => ['nullable', 'numeric', 'gt:0'],
            'approval_note' => ['nullable', 'string', 'max:200'],
        ]);

        try {
            $this->pengajuan->approve(
                $pengajuan,
                isset($data['approved_amount']) ? (float) $data['approved_amount'] : null,
                $data['approval_note'] ?? null,
                $request->user()?->id,
            );
        } catch (FinanceException $e) {
            return back()->withErrors(['approved_amount' => $e->getMessage()]);
        }

        return back()->with('status', 'Pengajuan disetujui, menunggu validasi.');
    }

    public function reject(Request $request, ExpenseRequest $pengajuan): RedirectResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:200']]);

        try {
            $this->pengajuan->reject($pengajuan, $data['reason'], $request->user()?->id);
        } catch (FinanceException $e) {
            return back()->withErrors(['reason' => $e->getMessage()]);
        }

        return back()->with('status', 'Pengajuan ditolak.');
    }

    public function validateApproval(Request $request, ExpenseRequest $pengajuan): RedirectResponse
    {
        try {
            $this->pengajuan->validateApproval($pengajuan, $request->user()?->id);
        } catch (FinanceException $e) {
            return back()->withErrors(['validated_at' => $e->getMessage()]);
        }

        return back()->with('status', 'Persetujuan tervalidasi, siap dicairkan.');
    }

    public function disburse(Request $request, ExpenseRequest $pengajuan): RedirectResponse
    {
        $data = $request->validate([
            'paid_on' => ['required', 'date'],
            'payment_method' => ['nullable', 'string', 'max:30'],
        ]);

        try {
            $this->pengajuan->disburse($pengajuan, $data, $request->user()?->id, $request->user()?->name);
        } catch (FinanceException $e) {
            return back()->withErrors(['paid_on' => $e->getMessage()]);
        }

        return back()->with('status', 'Biaya dicairkan dan tercatat sebagai kas keluar.');
    }
}
