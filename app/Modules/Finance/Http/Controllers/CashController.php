<?php

namespace App\Modules\Finance\Http\Controllers;

use App\Modules\Finance\Models\CashCategory;
use App\Modules\Finance\Models\CashTransaction;
use App\Modules\Finance\Services\CashService;
use App\Modules\Finance\Services\FinanceException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Kas harian (domain K item A): pemasukan dan pengeluaran di luar tagihan
 * pasien, berikut kategorinya dan laporan arus kasnya.
 *
 * Satu layar untuk delapan kode Khanza yang di sana jadi menu terpisah —
 * pola umbrella yang sama seperti domain I item D dan domain J.
 */
class CashController
{
    public function __construct(private readonly CashService $kas) {}

    public function index(Request $request): View
    {
        $dari = $request->query('dari', now()->startOfMonth()->toDateString());
        $sampai = $request->query('sampai', now()->toDateString());
        $kategoriId = $request->query('kategori') ? (int) $request->query('kategori') : null;
        $arah = $request->query('arah') ?: null;

        if ($arah !== null && ! in_array($arah, [CashCategory::MASUK, CashCategory::KELUAR], true)) {
            $arah = null;
        }

        return view('finance::kas.index', [
            'dari' => $dari,
            'sampai' => $sampai,
            'kategoriId' => $kategoriId,
            'arah' => $arah,

            'kategori' => $this->kas->categories(),
            'ringkasan' => $this->kas->summary($dari, $sampai, $kategoriId),
            'arusKas' => $this->kas->dailyCashflow($dari, $sampai, $kategoriId),
            'perKategori' => $this->kas->byCategory($dari, $sampai, $arah),
            'transaksi' => $this->kas->transactions($dari, $sampai, $kategoriId),
            'dibatalkan' => $this->kas->cancelledTransactions($dari, $sampai),
            'belumDipetakan' => $this->kas->unmappedTotal($dari, $sampai),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'category_id' => ['required', 'integer'],
            'transaction_date' => ['required', 'date'],
            'amount' => ['required', 'numeric', 'gt:0'],
            'description' => ['required', 'string', 'max:200'],
            'counterparty' => ['nullable', 'string', 'max:120'],
            'payment_method' => ['nullable', 'string', 'max:30'],
            'reference_number' => ['nullable', 'string', 'max:60'],
        ]);

        try {
            $this->kas->record($data, $request->user()?->id, $request->user()?->name);
        } catch (FinanceException $e) {
            return back()->withInput()->withErrors(['category_id' => $e->getMessage()]);
        }

        return back()->with('status', 'Transaksi kas tercatat.');
    }

    public function cancel(Request $request, CashTransaction $transaksi): RedirectResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:200']]);

        try {
            $this->kas->cancel($transaksi, $data['reason'], $request->user()?->id);
        } catch (FinanceException $e) {
            return back()->withErrors(['reason' => $e->getMessage()]);
        }

        return back()->with('status', 'Transaksi kas dibatalkan.');
    }

    public function storeCategory(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'max:30', Rule::unique(CashCategory::class, 'code')],
            'name' => ['required', 'string', 'max:120'],
            'direction' => ['required', Rule::in([CashCategory::MASUK, CashCategory::KELUAR])],
            'account_id' => ['nullable', 'integer'],
        ]);

        CashCategory::query()->create($data + ['is_active' => true]);

        return back()->with('status', 'Kategori kas ditambahkan.');
    }
}
