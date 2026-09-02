<?php

namespace App\Modules\Billing\Http\Controllers;

use App\Modules\Billing\Models\Invoice;
use App\Modules\Billing\Models\Payment;
use App\Modules\Billing\Services\BillingException;
use App\Modules\Billing\Services\InvoiceService;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class InvoiceController
{
    public function __construct(private readonly InvoiceService $invoices) {}

    /** Daftar tagihan kasir rawat jalan. */
    public function index(Request $request): View
    {
        $tanggal = CarbonImmutable::parse($request->query('tanggal', now()->toDateString()))->startOfDay();
        $status = $request->query('status');

        $daftar = Invoice::query()
            ->whereDate('opened_at', $tanggal->toDateString())
            ->when($status, fn ($q) => $q->where('status', $status))
            ->orderByRaw("CASE status WHEN 'terbuka' THEN 1 ELSE 2 END")
            ->orderByDesc('opened_at')
            ->paginate(50)
            ->withQueryString();

        $ringkasan = Invoice::query()
            ->whereDate('opened_at', $tanggal->toDateString())
            ->selectRaw('status, count(*) as jumlah, coalesce(sum(total_amount - paid_amount), 0) as sisa')
            ->groupBy('status')
            ->get()
            ->keyBy('status');

        return view('billing::invoices.index', [
            'daftar' => $daftar,
            'ringkasan' => $ringkasan,
            'tanggal' => $tanggal,
            'status' => $status,
        ]);
    }

    public function show(Invoice $tagihan): View
    {
        return view('billing::invoices.show', [
            'tagihan' => $tagihan->load(['chargeLines' => fn ($q) => $q->orderBy('charged_at'), 'payments']),
        ]);
    }

    /** Membuka atau melanjutkan tagihan untuk satu kunjungan. */
    public function openForRegistration(int $registrasi): RedirectResponse
    {
        try {
            $tagihan = $this->invoices->openInvoice($registrasi);
        } catch (BillingException $e) {
            return back()->with('galat', $e->getMessage());
        }

        return redirect()->route('tagihan.show', $tagihan);
    }

    /** Menyegarkan charge line dari konteks lain tanpa membuat tagihan baru. */
    public function refresh(Invoice $tagihan): RedirectResponse
    {
        try {
            $this->invoices->syncCharges($tagihan);
        } catch (BillingException $e) {
            return back()->with('galat', $e->getMessage());
        }

        return back()->with('sukses', 'Tagihan disegarkan dengan biaya terbaru.');
    }

    public function pay(Request $request, Invoice $tagihan): RedirectResponse
    {
        $data = $request->validate([
            'amount' => ['required', 'numeric', 'min:1'],
            'method' => ['required', 'in:tunai,debit,kredit,qris,transfer'],
            'note' => ['nullable', 'string', 'max:255'],
        ], [], ['amount' => 'jumlah pembayaran', 'method' => 'metode pembayaran']);

        try {
            $this->invoices->pay(
                invoice: $tagihan,
                amount: (float) $data['amount'],
                method: $data['method'],
                actor: $request->user(),
                note: $data['note'] ?? null,
            );
        } catch (BillingException $e) {
            return back()->withInput()->with('galat', $e->getMessage());
        }

        return back()->with('sukses', 'Pembayaran tercatat.');
    }

    public function voidPayment(Request $request, Payment $pembayaran): RedirectResponse
    {
        $data = $request->validate([
            'alasan' => ['required', 'string', 'min:5', 'max:255'],
        ], [], ['alasan' => 'alasan pembatalan']);

        try {
            $this->invoices->voidPayment($pembayaran, $data['alasan'], $request->user());
        } catch (BillingException $e) {
            return back()->with('galat', $e->getMessage());
        }

        return redirect()->route('tagihan.show', $pembayaran->invoice_id)
            ->with('sukses', 'Pembayaran dibatalkan.');
    }

    public function voidInvoice(Request $request, Invoice $tagihan): RedirectResponse
    {
        $data = $request->validate([
            'alasan' => ['required', 'string', 'min:5', 'max:255'],
        ], [], ['alasan' => 'alasan pembatalan']);

        try {
            $this->invoices->voidInvoice($tagihan, $data['alasan'], $request->user());
        } catch (BillingException $e) {
            return back()->with('galat', $e->getMessage());
        }

        return redirect()->route('tagihan.index')->with('sukses', 'Tagihan dibatalkan.');
    }
}
