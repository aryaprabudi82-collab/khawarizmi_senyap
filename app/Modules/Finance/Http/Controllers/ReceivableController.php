<?php

namespace App\Modules\Finance\Http\Controllers;

use App\Modules\Finance\Models\Receivable;
use App\Modules\Finance\Services\FinanceException;
use App\Modules\Finance\Services\PostingService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ReceivableController
{
    public function __construct(private readonly PostingService $posting) {}

    public function index(Request $request): View
    {
        // Menyegarkan dari billing setiap layar dibuka: sinkronisasi
        // idempoten, aman dipanggil berkali-kali.
        $baru = $this->posting->syncFromBilling();

        $status = $request->query('status', Receivable::STATUS_TERBUKA);

        $daftar = Receivable::query()
            ->when($status, fn ($q) => $q->where('status', $status))
            ->orderByDesc('opened_at')
            ->paginate(50)
            ->withQueryString();

        $ringkasan = Receivable::query()
            ->selectRaw('status, count(*) as jumlah, coalesce(sum(amount), 0) as total')
            ->groupBy('status')
            ->get()
            ->keyBy('status');

        return view('finance::receivables.index', [
            'daftar' => $daftar,
            'ringkasan' => $ringkasan,
            'status' => $status,
            'baruDiposting' => $baru,
        ]);
    }

    public function collect(Request $request, Receivable $piutang): RedirectResponse
    {
        $data = $request->validate([
            'reference' => ['required', 'string', 'max:100'],
        ], [], ['reference' => 'nomor bukti penerimaan']);

        try {
            $this->posting->collectReceivable($piutang, $data['reference'], $request->user());
        } catch (FinanceException $e) {
            return back()->with('galat', $e->getMessage());
        }

        return back()->with('sukses', "Piutang {$piutang->invoice_number} tercatat tertagih.");
    }
}
