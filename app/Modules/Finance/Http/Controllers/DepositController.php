<?php

namespace App\Modules\Finance\Http\Controllers;

use App\Modules\Finance\Models\Deposit;
use App\Modules\Finance\Services\DepositService;
use App\Modules\Finance\Services\FinanceException;
use App\Modules\Finance\Services\RegistrationContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class DepositController
{
    public function __construct(
        private readonly DepositService $deposits,
        private readonly RegistrationContext $registrations,
    ) {}

    /**
     * Layar deposit tidak punya konteks kunjungan siap pakai seperti
     * layar rekam medis/order — pencarian kunjungan di sini menggantikan
     * peran registrasi.index yang tidak bisa diakses petugas keuangan.
     */
    public function index(Request $request): View
    {
        $q = trim((string) $request->query('q', ''));

        $daftar = Deposit::query()
            ->orderByDesc('deposited_at')
            ->paginate(50)
            ->withQueryString();

        return view('finance::deposits.index', [
            'daftar' => $daftar,
            'q' => $q,
            'hasilPencarian' => $q !== '' ? $this->registrations->search($q) : collect(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'registration_id' => ['required', 'integer'],
            'amount' => ['required', 'numeric', 'min:1'],
            'note' => ['nullable', 'string', 'max:255'],
        ], [], ['registration_id' => 'kunjungan', 'amount' => 'jumlah deposit']);

        try {
            $deposit = $this->deposits->receive(
                $data['registration_id'],
                (float) $data['amount'],
                $data['note'] ?? null,
                $request->user(),
            );
        } catch (FinanceException $e) {
            return back()->with('galat', $e->getMessage())->withInput();
        }

        return redirect()->route('deposit.index')
            ->with('sukses', "Deposit {$deposit->deposit_number} sebesar Rp " . number_format((float) $deposit->amount, 0, ',', '.') . ' tercatat.');
    }
}
