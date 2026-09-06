<?php

namespace App\Modules\Finance\Http\Controllers;

use App\Modules\Finance\Models\OtherReceivable;
use App\Modules\Finance\Models\Payable;
use App\Modules\Finance\Models\ReceivableCategory;
use App\Modules\Finance\Services\FinanceException;
use App\Modules\Finance\Services\OtherReceivableService;
use App\Modules\Finance\Services\PayableService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Piutang non-pasien dan beban hutang lain (domain K item C).
 *
 * Keduanya di satu layar karena keduanya urusan "siapa berhutang kepada
 * siapa di luar pelayanan pasien" — tapi disimpan di mekanisme yang
 * berbeda sesuai arahnya: piutang di finance.other_receivables, beban
 * hutang lain di buku hutang yang sama dengan hutang vendor.
 */
class OtherReceivableController
{
    public function __construct(
        private readonly OtherReceivableService $piutang,
        private readonly PayableService $hutang,
    ) {}

    public function index(Request $request): View
    {
        $jenis = $request->query('jenis') ?: null;
        $dari = $request->query('dari', now()->startOfMonth()->toDateString());
        $sampai = $request->query('sampai', now()->toDateString());

        if ($jenis !== null && ! isset(ReceivableCategory::JENIS[$jenis])) {
            $jenis = null;
        }

        return view('finance::piutang-lain.index', [
            'jenis' => $jenis,
            'dari' => $dari,
            'sampai' => $sampai,
            'daftarJenis' => ReceivableCategory::JENIS,
            'kategori' => $this->piutang->categories(),

            'terutang' => $this->piutang->outstanding($jenis),
            'perJenis' => $this->piutang->byKind(),
            'perPihak' => $this->piutang->byDebtor($jenis),
            'umur' => $this->piutang->aging($jenis),
            'dihapuskan' => $this->piutang->writtenOff(),
            'pembayaran' => $this->piutang->payments($dari, $sampai),
            'belumDipetakan' => $this->piutang->unmappedTotal(),

            // Beban hutang lain — arah sebaliknya, dibaca dari buku hutang.
            'hutangLain' => $this->hutang->outstanding('lain'),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'category_id' => ['required', 'integer'],
            'debtor_name' => ['required', 'string', 'max:150'],
            'debtor_contact' => ['nullable', 'string', 'max:120'],
            'reference_number' => ['nullable', 'string', 'max:60'],
            'issued_on' => ['required', 'date'],
            'due_date' => ['required', 'date'],
            'amount' => ['required', 'numeric', 'gt:0'],
            'description' => ['required', 'string', 'max:200'],
        ]);

        try {
            $this->piutang->record($data, $request->user()?->id);
        } catch (FinanceException $e) {
            return back()->withInput()->withErrors(['category_id' => $e->getMessage()]);
        }

        return back()->with('status', 'Piutang tercatat.');
    }

    public function collect(Request $request, OtherReceivable $piutang): RedirectResponse
    {
        $data = $request->validate([
            'paid_on' => ['required', 'date'],
            'amount' => ['required', 'numeric', 'gt:0'],
            'payment_method' => ['nullable', 'string', 'max:30'],
            'reference_number' => ['nullable', 'string', 'max:60'],
        ]);

        try {
            $this->piutang->collect($piutang, $data, $request->user()?->id, $request->user()?->name);
        } catch (FinanceException $e) {
            return back()->withErrors(['amount' => $e->getMessage()]);
        }

        return back()->with('status', 'Pembayaran piutang tercatat.');
    }

    public function writeOff(Request $request, OtherReceivable $piutang): RedirectResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:200']]);

        try {
            $this->piutang->writeOff($piutang, $data['reason'], $request->user()?->id);
        } catch (FinanceException $e) {
            return back()->withErrors(['reason' => $e->getMessage()]);
        }

        return back()->with('status', 'Piutang dihapuskan; catatannya tetap tersimpan.');
    }

    public function storeCategory(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'max:30', Rule::unique(ReceivableCategory::class, 'code')],
            'name' => ['required', 'string', 'max:120'],
            'kind' => ['required', Rule::in(array_keys(ReceivableCategory::JENIS))],
            'account_id' => ['nullable', 'integer'],
        ]);

        ReceivableCategory::query()->create($data + ['is_active' => true]);

        return back()->with('status', 'Kategori piutang ditambahkan.');
    }

    /** Beban hutang lain — masuk buku hutang yang sama dengan hutang vendor. */
    public function storeOtherDebt(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'supplier_name' => ['required', 'string', 'max:150'],
            'invoice_number' => ['required', 'string', 'max:60'],
            'invoice_date' => ['required', 'date'],
            'due_date' => ['required', 'date'],
            'amount' => ['required', 'numeric', 'gt:0'],
        ]);

        try {
            $this->hutang->submit($data + ['source_context' => 'lain'], $request->user()?->id);
        } catch (FinanceException $e) {
            return back()->withInput()->withErrors(['invoice_number' => $e->getMessage()]);
        }

        return back()->with('status', 'Beban hutang lain tercatat dan langsung diakui.');
    }

    public function payOtherDebt(Request $request, Payable $hutang): RedirectResponse
    {
        $data = $request->validate([
            'paid_on' => ['required', 'date'],
            'amount' => ['required', 'numeric', 'gt:0'],
        ]);

        try {
            $this->hutang->pay($hutang, $data, $request->user()?->id, $request->user()?->name);
        } catch (FinanceException $e) {
            return back()->withErrors(['amount' => $e->getMessage()]);
        }

        return back()->with('status', 'Pembayaran beban hutang tercatat.');
    }
}
