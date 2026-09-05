<?php

namespace App\Modules\Billing\Http\Controllers;

use App\Modules\Billing\Models\Invoice;
use App\Modules\Billing\Models\PatientReceivable;
use App\Modules\Billing\Services\BillingException;
use App\Modules\Billing\Services\InvoiceService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Piutang pasien (piutang_pasien) — pasien pulang tanpa melunasi, sisanya
 * jatuh tempo dan dicicil.
 *
 * Beda dari piutang penjamin di konteks finance (bayar_piutang), yang
 * ditagih lewat klaim BPJS/asuransi dan ditutup dengan bukti transfer/SP2D.
 *
 * piutang_ralan dan piutang_ranap tidak jadi gerbang tersendiri: keduanya
 * piutang yang sama, dibedakan jenis rawatnya — pola yang sama dipakai
 * InvoiceController untuk pembayaran_ralan/pembayaran_ranap.
 */
class ReceivableController
{
    public function __construct(private readonly InvoiceService $invoices) {}

    public function index(Request $request): View
    {
        $jenisBoleh = $this->careTypesAllowed($request);

        abort_if($jenisBoleh === [], 403);

        $saring = $request->query('saring', 'belum-lunas');

        $daftar = PatientReceivable::query()
            ->berlaku()
            ->whereIn('care_type', $jenisBoleh)
            ->with('invoice')
            ->orderBy('due_date')
            ->get()
            ->filter(fn (PatientReceivable $p) => match ($saring) {
                'lunas' => $p->isSettled(),
                'terlambat' => $p->isOverdue(),
                default => ! $p->isSettled(),
            });

        return view('billing::piutang.index', [
            'daftar' => $daftar,
            'saring' => $saring,
            'jenisBoleh' => $jenisBoleh,
        ]);
    }

    public function store(Request $request, Invoice $tagihan): RedirectResponse
    {
        $this->assertAccess($request, $tagihan->care_type);

        $data = $request->validate([
            'due_date' => ['required', 'date'],
            'down_payment' => ['nullable', 'numeric', 'min:0'],
            'down_payment_method' => ['nullable', 'in:tunai,debit,kredit,qris,transfer'],
            'note' => ['nullable', 'string', 'max:1000'],
        ], [], [
            'due_date' => 'tanggal jatuh tempo', 'down_payment' => 'uang muka',
            'down_payment_method' => 'metode uang muka',
        ]);

        try {
            $this->invoices->createReceivable(
                $tagihan,
                $data['due_date'],
                $request->user(),
                (float) ($data['down_payment'] ?? 0),
                $data['down_payment_method'] ?? 'tunai',
                $data['note'] ?? null,
            );
        } catch (BillingException $e) {
            return back()->withInput()->with('galat', $e->getMessage());
        }

        return back()->with('sukses', 'Sisa tagihan dicatat sebagai piutang pasien.');
    }

    public function cancel(Request $request, PatientReceivable $piutang): RedirectResponse
    {
        $this->assertAccess($request, $piutang->care_type);

        $data = $request->validate([
            'alasan' => ['required', 'string', 'min:5', 'max:255'],
        ], [], ['alasan' => 'alasan pembatalan']);

        try {
            $this->invoices->cancelReceivable($piutang, $data['alasan'], $request->user());
        } catch (BillingException $e) {
            return back()->with('galat', $e->getMessage());
        }

        return back()->with('sukses', 'Piutang dibatalkan. Pembayaran yang sudah masuk tidak ikut dibatalkan.');
    }

    private function assertAccess(Request $request, string $careType): void
    {
        abort_unless(in_array($careType, $this->careTypesAllowed($request), true), 403);
    }

    /** @return list<string> */
    private function careTypesAllowed(Request $request): array
    {
        $pengguna = $request->user();

        // Satu kode piutang_pasien memayungi keduanya; peran yang hanya
        // memegang salah satu kasir tetap dibatasi ke jenis rawatnya.
        if ($pengguna?->can('piutang_pasien') !== true) {
            return [];
        }

        return array_values(array_filter([
            $pengguna->can('pembayaran_ralan') === true ? 'ralan' : null,
            $pengguna->can('pembayaran_ranap') === true ? 'ranap' : null,
        ]));
    }
}
