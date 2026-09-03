<?php

namespace App\Modules\Finance\Services;

use App\Modules\Finance\Models\Account;
use App\Modules\Finance\Models\Deposit;
use App\Modules\Finance\Models\JournalEntry;
use App\Modules\Platform\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * deposit_pasien — penerimaan titipan uang muka pasien.
 *
 * Dijurnal langsung di sini (bukan lewat PostingService, yang fokus ke
 * siklus invoice/piutang billing): Kas debit, Titipan Deposit Pasien
 * (akun bertipe 'utang') kredit — bukan pendapatan sampai dipakai, sama
 * seperti piutang penjamin belum menjadi kas sampai collectReceivable().
 *
 * Menerapkan deposit ke tagihan (status 'terpakai') SENGAJA belum ada di
 * sini — itu kelas Khanza terpisah (pengembalian_deposit_pasien, domain K),
 * lihat catatan migrasi.
 */
class DepositService
{
    public function __construct(private readonly RegistrationContext $registrations) {}

    /**
     * @throws FinanceException
     */
    public function receive(int $registrationId, float $amount, ?string $note, ?User $actor): Deposit
    {
        if ($amount <= 0) {
            throw new FinanceException('Jumlah deposit harus lebih dari nol.');
        }

        $kunjungan = $this->registrations->find($registrationId)
            ?? throw new FinanceException('Kunjungan tidak ditemukan atau sudah dibatalkan.');

        return DB::transaction(function () use ($kunjungan, $amount, $note, $actor): Deposit {
            $deposit = Deposit::query()->create([
                'deposit_number' => $this->allocateNumber('DEP'),
                'registration_id' => $kunjungan->id,
                'patient_id' => $kunjungan->patient_id,
                'patient_mrn' => $kunjungan->patient_mrn,
                'patient_name' => $kunjungan->patient_name,
                'payer_name' => $kunjungan->payer_name,
                'amount' => $amount,
                'status' => Deposit::STATUS_AKTIF,
                'note' => $note,
                'deposited_at' => now(),
                'deposited_by' => $actor?->id,
                'deposited_by_name' => $actor?->name,
            ]);

            $kas = $this->account(Account::TYPE_KAS);
            $titipan = $this->account(Account::TYPE_UTANG);

            $entry = JournalEntry::query()->create([
                'entry_number' => $this->allocateNumber('JRN'),
                'entry_date' => now(),
                'description' => 'Deposit diterima ' . $deposit->deposit_number . ' — ' . $deposit->patient_name,
                'reference_type' => 'deposit-diterima',
                'reference_id' => $deposit->id,
                'posted_by' => $actor?->id,
            ]);

            $entry->lines()->create(['account_id' => $kas->id, 'debit' => $amount, 'credit' => 0]);
            $entry->lines()->create(['account_id' => $titipan->id, 'debit' => 0, 'credit' => $amount]);

            return $deposit;
        });
    }

    private function account(string $type): Account
    {
        return Account::query()->where('type', $type)->where('is_active', true)->firstOrFail();
    }

    private function allocateNumber(string $prefix): string
    {
        $key = $prefix . now()->format('Ymd');

        $row = DB::selectOne(
            'INSERT INTO finance.number_sequences (prefix, last_number, updated_at)
             VALUES (?, 1, now())
             ON CONFLICT (prefix) DO UPDATE
                SET last_number = finance.number_sequences.last_number + 1,
                    updated_at  = now()
             RETURNING last_number',
            [$key]
        );

        return $key . '-' . str_pad((string) $row->last_number, 5, '0', STR_PAD_LEFT);
    }
}
