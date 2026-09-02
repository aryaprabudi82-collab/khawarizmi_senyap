<?php

namespace App\Modules\Finance\Services;

use App\Modules\Finance\Models\Account;
use App\Modules\Finance\Models\JournalEntry;
use App\Modules\Finance\Models\Receivable;
use App\Modules\Platform\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Pintu masuk konteks finance.
 *
 * post() adalah SATU-SATUNYA jalan menulis jurnal. Ia selalu menulis baris
 * debit dan kredit bernilai sama dalam satu transaksi — jurnal berpasangan
 * ditegakkan di sini, bukan diharap dari disiplin pemanggil di luar kelas ini.
 *
 * Keterbatasan yang diketahui dan sengaja belum ditangani di Wave 1:
 *
 *  - Bila pembayaran tagihan 'lunas' dibatalkan (voidPayment di billing)
 *    setelah jurnalnya sudah diposting, jurnal itu TIDAK otomatis dibalik.
 *    Penanganannya butuh entri jurnal pembalik terpicu dari pembatalan
 *    pembayaran - menyusul bersama modul finance yang lebih lengkap.
 *  - collectReceivable() mewakili penerimaan pembayaran secara sederhana,
 *    satu piutang sekali tagih. Penagihan sesungguhnya ke BPJS/asuransi
 *    (verifikasi klaim, pembayaran batch mencakup ratusan SEP sekaligus)
 *    adalah proses INACBG/bridging yang jauh lebih rumit dari cakupan ini.
 */
class PostingService
{
    public function __construct(private readonly InvoiceContext $invoices) {}

    /**
     * Memposting seluruh tagihan tuntas yang belum dijurnal.
     *
     * Idempoten lewat unique index (reference_type, reference_id) di
     * journal_entries - dijalankan berkali-kali tidak pernah memposting
     * tagihan yang sama dua kali.
     *
     * @return int jumlah tagihan yang baru diposting
     */
    public function syncFromBilling(): int
    {
        $diposting = 0;

        foreach ($this->invoices->unpostedSettled() as $tagihan) {
            $this->postInvoice($tagihan);
            $diposting++;
        }

        return $diposting;
    }

    /**
     * @throws FinanceException
     */
    private function postInvoice(object $tagihan): JournalEntry
    {
        $kas = $this->account(Account::TYPE_KAS);
        $pendapatan = $this->account(Account::TYPE_PENDAPATAN);
        $piutang = $this->account(Account::TYPE_PIUTANG);

        $jumlah = (float) $tagihan->total_amount;

        return DB::transaction(function () use ($tagihan, $jumlah, $kas, $pendapatan, $piutang): JournalEntry {
            $entry = JournalEntry::query()->create([
                'entry_number' => $this->allocateNumber('JRN'),
                'entry_date' => $tagihan->closed_at,
                'description' => $jumlah > 0
                    ? 'Pendapatan ' . $tagihan->invoice_number . ' — ' . $tagihan->patient_name
                    : $tagihan->invoice_number . ' — tidak ada nilai untuk diposting (mis. registrasi BPJS tanpa biaya)',
                'reference_type' => 'invoice',
                'reference_id' => $tagihan->invoice_id,
            ]);

            /*
             * Tagihan bernilai nol (mis. registrasi BPJS yang memang gratis
             * bagi pasien) tidak menghasilkan baris jurnal maupun piutang -
             * tidak ada nilai uang untuk diakui atau ditagih. Header jurnal
             * tetap dibuat kosong sebagai penanda "sudah diperiksa", supaya
             * idempotensi berbasis (reference_type, reference_id) tetap
             * menahan invoice ini agar tidak diproses ulang setiap sinkronisasi.
             */
            if ($jumlah <= 0) {
                return $entry;
            }

            if ($tagihan->payment_responsibility === 'pasien') {
                // Dibayar tunai di kasir: Kas bertambah, Pendapatan diakui.
                $entry->lines()->create(['account_id' => $kas->id, 'debit' => $jumlah, 'credit' => 0]);
                $entry->lines()->create(['account_id' => $pendapatan->id, 'debit' => 0, 'credit' => $jumlah]);
            } else {
                // Ditanggung penjamin: Piutang terbuka, Pendapatan tetap diakui
                // saat ini juga (accrual), bukan menunggu penjamin membayar.
                $entry->lines()->create(['account_id' => $piutang->id, 'debit' => $jumlah, 'credit' => 0]);
                $entry->lines()->create(['account_id' => $pendapatan->id, 'debit' => 0, 'credit' => $jumlah]);

                Receivable::query()->create([
                    'invoice_id' => $tagihan->invoice_id,
                    'registration_id' => $tagihan->registration_id,
                    'patient_id' => $tagihan->patient_id,
                    'payer_id' => $tagihan->payer_id,
                    'invoice_number' => $tagihan->invoice_number,
                    'patient_name' => $tagihan->patient_name,
                    'payer_name' => $tagihan->payer_name,
                    'payer_kind' => $tagihan->payer_kind,
                    'amount' => $jumlah,
                    'status' => Receivable::STATUS_TERBUKA,
                    'opened_at' => $tagihan->closed_at,
                ]);
            }

            return $entry;
        });
    }

    /**
     * Mencatat penerimaan pembayaran dari penjamin.
     *
     * @throws FinanceException
     */
    public function collectReceivable(
        Receivable $receivable,
        string $reference,
        ?User $actor = null,
    ): Receivable {
        if ($receivable->isCollected()) {
            throw new FinanceException('Piutang ini sudah tertagih sebelumnya.');
        }

        return DB::transaction(function () use ($receivable, $reference, $actor): Receivable {
            $kas = $this->account(Account::TYPE_KAS);
            $piutang = $this->account(Account::TYPE_PIUTANG);
            $jumlah = (float) $receivable->amount;

            $entry = JournalEntry::query()->create([
                'entry_number' => $this->allocateNumber('JRN'),
                'entry_date' => now(),
                'description' => 'Penerimaan piutang ' . $receivable->invoice_number . ' dari ' . $receivable->payer_name,
                'reference_type' => 'penerimaan-piutang',
                'reference_id' => $receivable->id,
                'posted_by' => $actor?->id,
            ]);

            // Kas bertambah, Piutang berkurang.
            $entry->lines()->create(['account_id' => $kas->id, 'debit' => $jumlah, 'credit' => 0]);
            $entry->lines()->create(['account_id' => $piutang->id, 'debit' => 0, 'credit' => $jumlah]);

            $receivable->update([
                'status' => Receivable::STATUS_TERTAGIH,
                'collected_at' => now(),
                'collected_by' => $actor?->id,
                'collection_reference' => $reference,
            ]);

            return $receivable->refresh();
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
