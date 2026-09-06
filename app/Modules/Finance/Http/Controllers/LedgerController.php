<?php

namespace App\Modules\Finance\Http\Controllers;

use App\Modules\Finance\Models\Account;
use App\Modules\Finance\Services\FinanceException;
use App\Modules\Finance\Services\LedgerService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Bagan akun, jurnal manual & buku besar (domain K item E).
 *
 * Layar inilah yang membuat seluruh pemetaan akun di kas, hutang, dan
 * piutang bisa diisi — sebelumnya tidak ada cara membuat akun sama sekali.
 */
class LedgerController
{
    public function __construct(private readonly LedgerService $buku) {}

    public function index(Request $request): View
    {
        $tahun = (int) $request->query('tahun', now()->year);
        $dari = $request->query('dari', now()->startOfYear()->toDateString());
        $sampai = $request->query('sampai', now()->toDateString());
        $akunId = $request->query('akun') ? (int) $request->query('akun') : null;
        $jenis = $request->query('jenis') ?: null;

        $akun = $akunId ? Account::query()->find($akunId) : null;

        return view('finance::buku.index', [
            'tahun' => $tahun,
            'dari' => $dari,
            'sampai' => $sampai,
            'jenis' => $jenis,
            'akunTerpilih' => $akun,
            'daftarJenis' => Account::JENIS,

            'akun' => $this->buku->accounts($jenis),
            'akunAktif' => $this->buku->accounts(null, true),
            'saldoAwal' => $this->buku->openingBalances($tahun),
            'jurnal' => $this->buku->dailyJournal($dari, $sampai),
            'perBulan' => $this->buku->monthlyBalances($tahun, $jenis),
            'neraca' => $this->buku->trialBalance($dari, $sampai),
            'bukuBesar' => $akun ? $this->buku->ledger($akun, $dari, $sampai) : null,
        ]);
    }

    public function storeAccount(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'max:20'],
            'name' => ['required', 'string', 'max:120'],
            'type' => ['required', Rule::in(Account::JENIS)],
        ]);

        try {
            $this->buku->createAccount($data);
        } catch (FinanceException $e) {
            return back()->withInput()->withErrors(['code' => $e->getMessage()]);
        }

        return back()->with('status', 'Akun ditambahkan ke bagan akun.');
    }

    public function deactivateAccount(Account $akun): RedirectResponse
    {
        try {
            $this->buku->deactivateAccount($akun);
        } catch (FinanceException $e) {
            return back()->withErrors(['is_active' => $e->getMessage()]);
        }

        return back()->with('status', 'Akun dinonaktifkan; riwayatnya tetap tersimpan.');
    }

    public function storeOpeningBalance(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'account_id' => ['required', 'integer'],
            'fiscal_year' => ['required', 'integer', 'min:2000', 'max:2100'],
            'opening_debit' => ['nullable', 'numeric', 'min:0'],
            'opening_credit' => ['nullable', 'numeric', 'min:0'],
        ]);

        $akun = Account::query()->findOrFail($data['account_id']);

        try {
            $this->buku->setOpeningBalance(
                $akun,
                (int) $data['fiscal_year'],
                (float) ($data['opening_debit'] ?? 0),
                (float) ($data['opening_credit'] ?? 0),
                $request->user()?->id,
            );
        } catch (FinanceException $e) {
            return back()->withInput()->withErrors(['opening_debit' => $e->getMessage()]);
        }

        return back()->with('status', 'Saldo awal tersimpan.');
    }

    public function postJournal(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'entry_date' => ['required', 'date'],
            'description' => ['required', 'string', 'max:200'],
            'lines' => ['required', 'array', 'min:2'],
            'lines.*.account_id' => ['required', 'integer'],
            'lines.*.debit' => ['nullable', 'numeric', 'min:0'],
            'lines.*.credit' => ['nullable', 'numeric', 'min:0'],
            'lines.*.description' => ['nullable', 'string', 'max:200'],
        ]);

        // Baris kosong dibuang sebelum divalidasi seimbang: formulir
        // menyediakan beberapa baris kosong, dan baris yang tidak diisi
        // bukan kesalahan pengguna.
        $lines = array_values(array_filter(
            $data['lines'],
            fn ($b) => (float) ($b['debit'] ?? 0) > 0 || (float) ($b['credit'] ?? 0) > 0
        ));

        try {
            $this->buku->postManual($data['entry_date'], $data['description'], $lines, $request->user()?->id);
        } catch (FinanceException $e) {
            return back()->withInput()->withErrors(['lines' => $e->getMessage()]);
        }

        return back()->with('status', 'Jurnal manual diposting.');
    }
}
