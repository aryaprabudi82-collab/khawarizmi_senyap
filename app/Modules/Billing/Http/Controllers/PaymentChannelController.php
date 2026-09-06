<?php

namespace App\Modules\Billing\Http\Controllers;

use App\Modules\Billing\Models\ChannelPayment;
use App\Modules\Billing\Models\Invoice;
use App\Modules\Billing\Models\PaymentChannel;
use App\Modules\Billing\Services\BillingException;
use App\Modules\Billing\Services\PaymentChannelService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Kanal pembayaran bank (domain K item G).
 *
 * Satu layar untuk semua bank. Mengelola kanalnya digerbangi terpisah
 * dari mencocokkan pembayaran: menentukan rekening mana yang sah menerima
 * uang rumah sakit adalah kewenangan yang berbeda dari mencocokkan setoran
 * harian.
 */
class PaymentChannelController
{
    public function __construct(private readonly PaymentChannelService $kanal) {}

    public function index(Request $request): View
    {
        $channelId = $request->query('kanal') ? (int) $request->query('kanal') : null;
        $status = $request->query('status') ?: null;
        $dari = $request->query('dari', now()->startOfMonth()->toDateString());
        $sampai = $request->query('sampai', now()->toDateString());

        return view('billing::kanal.index', [
            'channelId' => $channelId,
            'status' => $status,
            'dari' => $dari,
            'sampai' => $sampai,
            'jenisKanal' => PaymentChannel::JENIS,

            'kanal' => $this->kanal->channels(),
            'kanalAktif' => $this->kanal->channels(true),
            'menggantung' => $this->kanal->unmatched($channelId),
            'inbox' => $this->kanal->inbox($channelId, $status),
            'rekap' => $this->kanal->recapByChannel($dari, $sampai),
        ]);
    }

    public function storeChannel(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'max:30'],
            'name' => ['required', 'string', 'max:120'],
            'kind' => ['required', Rule::in(PaymentChannel::JENIS)],
            'bank_name' => ['nullable', 'string', 'max:120'],
            'account_number' => ['nullable', 'string', 'max:60'],
            'allows_ralan' => ['nullable', 'boolean'],
            'allows_ranap' => ['nullable', 'boolean'],
        ]);

        $data['allows_ralan'] = $request->boolean('allows_ralan', true);
        $data['allows_ranap'] = $request->boolean('allows_ranap', true);

        try {
            $this->kanal->saveChannel($data);
        } catch (BillingException $e) {
            return back()->withInput()->withErrors(['code' => $e->getMessage()]);
        }

        return back()->with('status', 'Kanal pembayaran ditambahkan.');
    }

    public function receive(Request $request, PaymentChannel $kanal): RedirectResponse
    {
        $data = $request->validate([
            'reference_number' => ['required', 'string', 'max:80'],
            'virtual_account' => ['nullable', 'string', 'max:60'],
            'payer_name' => ['nullable', 'string', 'max:150'],
            'amount' => ['required', 'numeric', 'gt:0'],
            'paid_at' => ['required', 'date'],
        ]);

        try {
            $this->kanal->receive($kanal, $data, $request->user()?->id);
        } catch (BillingException $e) {
            return back()->withInput()->withErrors(['reference_number' => $e->getMessage()]);
        }

        return back()->with('status', 'Pemberitahuan pembayaran diterima, menunggu dicocokkan.');
    }

    public function match(Request $request, ChannelPayment $pembayaran): RedirectResponse
    {
        $data = $request->validate(['invoice_id' => ['required', 'integer']]);

        $tagihan = Invoice::query()->findOrFail($data['invoice_id']);

        try {
            $this->kanal->match($pembayaran, $tagihan, $request->user());
        } catch (BillingException $e) {
            return back()->withErrors(['invoice_id' => $e->getMessage()]);
        }

        return back()->with('status', 'Pembayaran dicocokkan dan tercatat pada tagihannya.');
    }

    public function reject(Request $request, ChannelPayment $pembayaran): RedirectResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:200']]);

        try {
            $this->kanal->reject($pembayaran, $data['reason'], $request->user()?->id);
        } catch (BillingException $e) {
            return back()->withErrors(['reason' => $e->getMessage()]);
        }

        return back()->with('status', 'Pemberitahuan ditolak.');
    }
}
