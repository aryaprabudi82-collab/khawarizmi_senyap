<?php

namespace App\Modules\Billing\Http\Controllers;

use App\Modules\Billing\Models\Invoice;
use App\Modules\Billing\Models\ManualAdjustment;
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

    /** Daftar tagihan kasir — rawat jalan, rawat inap, atau keduanya, tergantung hak pengguna. */
    public function index(Request $request): View
    {
        $tanggal = CarbonImmutable::parse($request->query('tanggal', now()->toDateString()))->startOfDay();
        $status = $request->query('status');
        $jenisBoleh = $this->careTypesAllowed($request);

        abort_if($jenisBoleh === [], 403);

        $daftar = Invoice::query()
            ->whereIn('care_type', $jenisBoleh)
            ->whereDate('opened_at', $tanggal->toDateString())
            ->when($status, fn ($q) => $q->where('status', $status))
            ->orderByRaw("CASE status WHEN 'terbuka' THEN 1 ELSE 2 END")
            ->orderByDesc('opened_at')
            ->paginate(50)
            ->withQueryString();

        $ringkasan = Invoice::query()
            ->whereIn('care_type', $jenisBoleh)
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
            'jenisBoleh' => $jenisBoleh,
        ]);
    }

    public function show(Request $request, Invoice $tagihan): View
    {
        $this->assertAccess($request, $tagihan);

        return view('billing::invoices.show', [
            'tagihan' => $tagihan->load(['chargeLines' => fn ($q) => $q->orderBy('charged_at'), 'payments', 'manualAdjustments']),
        ]);
    }

    /**
     * Membuka atau melanjutkan tagihan untuk satu kunjungan.
     *
     * Jenis rawatnya baru diketahui setelah tagihan terbuka (ia disalin
     * dari registrasi), jadi pemeriksaan hak dilakukan sesudahnya.
     * openInvoice() idempoten — memanggilnya tidak menggandakan tagihan.
     */
    public function openForRegistration(Request $request, int $registrasi): RedirectResponse
    {
        try {
            $tagihan = $this->invoices->openInvoice($registrasi);
        } catch (BillingException $e) {
            return back()->with('galat', $e->getMessage());
        }

        $this->assertAccess($request, $tagihan);

        return redirect()->route('tagihan.show', $tagihan);
    }

    /** Menyegarkan charge line dari konteks lain tanpa membuat tagihan baru. */
    public function refresh(Request $request, Invoice $tagihan): RedirectResponse
    {
        $this->assertAccess($request, $tagihan);

        try {
            $this->invoices->syncCharges($tagihan);
        } catch (BillingException $e) {
            return back()->with('galat', $e->getMessage());
        }

        return back()->with('sukses', 'Tagihan disegarkan dengan biaya terbaru.');
    }

    public function pay(Request $request, Invoice $tagihan): RedirectResponse
    {
        $this->assertAccess($request, $tagihan);

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

        $this->assertAccess($request, $pembayaran->invoice);

        try {
            $this->invoices->voidPayment($pembayaran, $data['alasan'], $request->user());
        } catch (BillingException $e) {
            return back()->with('galat', $e->getMessage());
        }

        return redirect()->route('tagihan.show', $pembayaran->invoice_id)
            ->with('sukses', 'Pembayaran dibatalkan.');
    }

    /** tambahan_biaya & potongan_biaya — dua kode Khanza, satu aksi, dibedakan kolom kind. */
    public function addAdjustment(Request $request, Invoice $tagihan): RedirectResponse
    {
        $this->assertAccess($request, $tagihan);

        $data = $request->validate([
            'kind' => ['required', 'in:tambahan,potongan'],
            'description' => ['required', 'string', 'max:100'],
            'amount' => ['required', 'numeric', 'gt:0'],
            'reason' => ['nullable', 'string', 'max:1000'],
        ], [], [
            'kind' => 'jenis penyesuaian', 'description' => 'keterangan',
            'amount' => 'nilai', 'reason' => 'alasan',
        ]);

        try {
            $this->invoices->addAdjustment(
                $tagihan,
                $data['kind'],
                $data['description'],
                (float) $data['amount'],
                $request->user(),
                $data['reason'] ?? null,
            );
        } catch (BillingException $e) {
            return back()->withInput()->with('galat', $e->getMessage());
        }

        return back()->with('sukses', $data['kind'] === 'potongan' ? 'Potongan biaya tercatat.' : 'Tambahan biaya tercatat.');
    }

    public function voidAdjustment(Request $request, ManualAdjustment $penyesuaian): RedirectResponse
    {
        $data = $request->validate([
            'alasan' => ['required', 'string', 'min:5', 'max:255'],
        ], [], ['alasan' => 'alasan pembatalan']);

        $this->assertAccess($request, $penyesuaian->invoice);

        try {
            $this->invoices->voidAdjustment($penyesuaian, $data['alasan'], $request->user());
        } catch (BillingException $e) {
            return back()->with('galat', $e->getMessage());
        }

        return redirect()->route('tagihan.show', $penyesuaian->invoice_id)
            ->with('sukses', 'Penyesuaian dibatalkan.');
    }
    public function voidInvoice(Request $request, Invoice $tagihan): RedirectResponse
    {
        $this->assertAccess($request, $tagihan);

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

    /**
     * Khanza memisahkan kasir rawat jalan (pembayaran_ralan) dan rawat inap
     * (pembayaran_ranap) — di rumah sakit sungguhan keduanya sering loket
     * dan orang berbeda. Satu layar di sini melayani keduanya, jadi
     * permission-nya diperiksa per tagihan, bukan lewat middleware rute:
     * pola yang sama dipakai OrderController::assertAccess() untuk lab/
     * radiologi/PA yang juga berbagi satu layar.
     */
    private function assertAccess(Request $request, Invoice $tagihan): void
    {
        abort_unless(in_array($tagihan->care_type, $this->careTypesAllowed($request), true), 403);
    }

    /** @return list<string> jenis rawat yang boleh dilihat pengguna ini */
    private function careTypesAllowed(Request $request): array
    {
        $pengguna = $request->user();

        return array_values(array_filter([
            $pengguna?->can('pembayaran_ralan') === true ? 'ralan' : null,
            $pengguna?->can('pembayaran_ranap') === true ? 'ranap' : null,
        ]));
    }
}
