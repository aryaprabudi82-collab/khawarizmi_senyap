<?php

namespace App\Modules\Finance\Http\Controllers;

use App\Modules\Finance\Models\Payable;
use App\Modules\Finance\Services\FinanceException;
use App\Modules\Finance\Services\PayableService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Hutang vendor (domain K item B): titip faktur, validasi, pelunasan,
 * dan ringkasannya — satu layar untuk empat rantai pengadaan.
 *
 * Validasi digerbangi TERPISAH dari pencatatan: yang menitipkan faktur
 * dan yang mengakui hutangnya sebaiknya bukan orang yang sama.
 */
class PayableController
{
    public function __construct(private readonly PayableService $hutang) {}

    public function index(Request $request): View
    {
        $sumber = $request->query('sumber') ?: null;
        $vendor = $request->query('vendor') ?: null;
        $dari = $request->query('dari', now()->startOfMonth()->toDateString());
        $sampai = $request->query('sampai', now()->toDateString());

        if ($sumber !== null && ! isset(Payable::SUMBER[$sumber])) {
            $sumber = null;
        }

        return view('finance::hutang.index', [
            'sumber' => $sumber,
            'vendor' => $vendor,
            'dari' => $dari,
            'sampai' => $sampai,
            'daftarSumber' => Payable::SUMBER,

            'terutang' => $this->hutang->outstanding($sumber, $vendor),
            'umur' => $this->hutang->aging($sumber),
            'perVendor' => $this->hutang->bySupplier($sumber),
            'perSumber' => $this->hutang->bySource(),
            'menunggu' => $this->hutang->pending($sumber),
            'pembayaran' => $this->hutang->payments($dari, $sampai),
            'belumDipetakan' => $this->hutang->unmappedTotal(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'source_context' => ['required', Rule::in(array_keys(Payable::SUMBER))],
            'supplier_name' => ['required', 'string', 'max:150'],
            'invoice_number' => ['required', 'string', 'max:60'],
            'invoice_date' => ['required', 'date'],
            'due_date' => ['required', 'date'],
            'amount' => ['required', 'numeric', 'gt:0'],
            'receipt_number' => ['nullable', 'string', 'max:30'],
            'goods_receipt_id' => ['nullable', 'integer'],
            'account_id' => ['nullable', 'integer'],
        ]);

        try {
            $this->hutang->submit($data, $request->user()?->id);
        } catch (FinanceException $e) {
            return back()->withInput()->withErrors(['invoice_number' => $e->getMessage()]);
        }

        return back()->with('status', 'Faktur dititipkan, menunggu validasi.');
    }

    public function validateInvoice(Request $request, Payable $hutang): RedirectResponse
    {
        $data = $request->validate(['amount' => ['nullable', 'numeric', 'gt:0']]);

        try {
            $this->hutang->validate($hutang, $data['amount'] ?? null, $request->user()?->id);
        } catch (FinanceException $e) {
            return back()->withErrors(['amount' => $e->getMessage()]);
        }

        return back()->with('status', 'Faktur tervalidasi; nilainya dibekukan.');
    }

    public function reject(Request $request, Payable $hutang): RedirectResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:200']]);

        try {
            $this->hutang->reject($hutang, $data['reason'], $request->user()?->id);
        } catch (FinanceException $e) {
            return back()->withErrors(['reason' => $e->getMessage()]);
        }

        return back()->with('status', 'Faktur ditolak.');
    }

    public function pay(Request $request, Payable $hutang): RedirectResponse
    {
        $data = $request->validate([
            'paid_on' => ['required', 'date'],
            'amount' => ['required', 'numeric', 'gt:0'],
            'payment_method' => ['nullable', 'string', 'max:30'],
            'reference_number' => ['nullable', 'string', 'max:60'],
            'note' => ['nullable', 'string', 'max:200'],
        ]);

        try {
            $this->hutang->pay($hutang, $data, $request->user()?->id, $request->user()?->name);
        } catch (FinanceException $e) {
            return back()->withErrors(['amount' => $e->getMessage()]);
        }

        return back()->with('status', 'Pembayaran hutang tercatat.');
    }
}
