<?php

namespace App\Modules\Finance\Http\Controllers;

use App\Modules\Finance\Models\Account;
use App\Modules\Finance\Models\AccountMapping;
use App\Modules\Finance\Models\PeriodClosing;
use App\Modules\Finance\Services\AccountingReportService;
use App\Modules\Finance\Services\FinanceException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\View\View;

/**
 * Layar akuntansi (domain I item E): pemetaan uang ke akun, laporan per
 * akun, dan penutupan periode.
 *
 * pendapatan_per_akun menggerbangi pemetaan dan laporannya;
 * pendapatan_per_akun_closing menggerbangi penutupan periode secara
 * terpisah, karena menutup buku adalah tindakan yang konsekuensinya
 * berbeda dari sekadar melihat laporan.
 */
class AccountingController
{
    public function __construct(private readonly AccountingReportService $akuntansi) {}

    public function index(Request $request): View
    {
        $dari = Carbon::parse($request->query('dari', now()->startOfMonth()->toDateString()))->toDateString();
        $sampai = Carbon::parse($request->query('sampai', now()->endOfMonth()->toDateString()))->toDateString();

        return view('finance::akuntansi.index', [
            'dari' => $dari,
            'sampai' => $sampai,
            'perCaraBayar' => $this->akuntansi->paymentsByAccount($dari, $sampai),
            'perSumber' => $this->akuntansi->revenueByAccount($dari, $sampai),
            'belumDipetakan' => $this->akuntansi->unmappedTotal($dari, $sampai),
            'akun' => Account::query()->where('is_active', true)->orderBy('code')->get(),
            'pemetaan' => AccountMapping::query()->with('account')->orderBy('kind')->orderBy('key')->get(),
            'penutupan' => PeriodClosing::query()->with('lines')->latest('closed_at')->limit(12)->get(),
        ]);
    }

    public function storeMapping(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'kind' => ['required', 'in:cara-bayar,sumber-pendapatan'],
            'key' => ['required', 'string', 'max:40'],
            'account_id' => ['required', 'integer'],
            'note' => ['nullable', 'string', 'max:255'],
        ], [], ['kind' => 'jenis pemetaan', 'key' => 'kunci', 'account_id' => 'akun']);

        AccountMapping::query()->updateOrCreate(
            ['kind' => $data['kind'], 'key' => $data['key']],
            [
                'account_id' => $data['account_id'],
                'note' => $data['note'] ?? null,
                'updated_by' => $request->user()->id,
            ]
        );

        return back()->with('sukses', 'Pemetaan akun disimpan.');
    }

    public function close(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'tahun' => ['required', 'integer', 'min:2000', 'max:2100'],
            'bulan' => ['required', 'integer', 'min:1', 'max:12'],
            'catatan' => ['nullable', 'string', 'max:1000'],
        ], [], ['tahun' => 'tahun periode', 'bulan' => 'bulan periode']);

        try {
            $this->akuntansi->closePeriod((int) $data['tahun'], (int) $data['bulan'], $request->user(), $data['catatan'] ?? null);
        } catch (FinanceException $e) {
            return back()->with('galat', $e->getMessage());
        }

        return back()->with('sukses', "Periode {$data['bulan']}-{$data['tahun']} ditutup, angkanya dibekukan.");
    }

    public function reopen(Request $request, PeriodClosing $penutupan): RedirectResponse
    {
        $data = $request->validate([
            'alasan' => ['required', 'string', 'min:5', 'max:255'],
        ], [], ['alasan' => 'alasan membuka kembali']);

        try {
            $this->akuntansi->reopenPeriod($penutupan, $data['alasan'], $request->user());
        } catch (FinanceException $e) {
            return back()->with('galat', $e->getMessage());
        }

        return back()->with('sukses', 'Periode dibuka kembali. Alasannya tercatat.');
    }
}
