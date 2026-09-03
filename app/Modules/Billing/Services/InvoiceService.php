<?php

namespace App\Modules\Billing\Services;

use App\Modules\Billing\Models\ChargeLine;
use App\Modules\Billing\Models\Invoice;
use App\Modules\Billing\Models\Payment;
use App\Modules\Platform\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Pintu masuk konteks billing.
 *
 * Tagihan tidak ditulis manual. Ia dibuka dari satu kunjungan, lalu diisi
 * lewat sinkronisasi charge line dari konteks lain — encounter (biaya
 * registrasi), pharmacy (obat yang diserahkan), order (pemeriksaan lab/
 * radiologi yang selesai), dan clinical (tindakan_ralan, menyusul setelah
 * domain A digarap berurutan — lihat catatan migrasi clinical.procedures).
 *
 * Penjamin selain 'umum' tidak menagih pasien di kasir rawat jalan: tagihan
 * itu langsung ditandai 'ditanggung-penjamin' dan menunggu alur klaim di
 * domain finance/integration yang belum dibangun.
 */
class InvoiceService
{
    public function __construct(
        private readonly RegistrationContext $registrations,
        private readonly PayerContext $payers,
        private readonly PrescriptionChargeContext $prescriptionCharges,
        private readonly OrderChargeContext $orderCharges,
        private readonly ProcedureChargeContext $procedureCharges,
    ) {}

    /**
     * Membuka tagihan untuk satu kunjungan, atau melanjutkan yang sudah ada
     * sambil menarik charge line terbaru.
     *
     * @throws BillingException
     */
    public function openInvoice(int $registrationId, ?User $actor = null): Invoice
    {
        $kunjungan = $this->registrations->find($registrationId)
            ?? throw new BillingException('Kunjungan tidak ditemukan atau sudah dibatalkan.');

        $invoice = Invoice::query()->where('registration_id', $registrationId)->first();

        if ($invoice === null) {
            $payer = $this->payers->find($kunjungan->payer_id)
                ?? throw new BillingException('Penjamin kunjungan ini tidak ditemukan.');

            $tanggungan = $payer->kind === 'umum'
                ? Invoice::RESPONSIBILITY_PASIEN
                : Invoice::RESPONSIBILITY_PENJAMIN;

            $invoice = Invoice::query()->create([
                'invoice_number' => $this->allocateNumber(),
                'registration_id' => $kunjungan->id,
                'patient_id' => $kunjungan->patient_id,
                'payer_id' => $kunjungan->payer_id,
                'registration_number' => $kunjungan->registration_number,
                'patient_mrn' => $kunjungan->patient_mrn,
                'patient_name' => $kunjungan->patient_name,
                'unit_name' => $kunjungan->unit_name,
                'payer_name' => $kunjungan->payer_name,
                'payer_kind' => $payer->kind,
                'payment_responsibility' => $tanggungan,
                'status' => Invoice::STATUS_TERBUKA,
                'opened_at' => now(),
                'created_by' => $actor?->id,
            ]);
        }

        if (! $invoice->isVoid()) {
            $this->syncCharges($invoice);
        }

        return $invoice->refresh();
    }

    /**
     * Menarik charge line terbaru dari konteks lain. Idempoten: peristiwa
     * sumber yang sama tidak pernah masuk dua kali, dijamin unique index
     * pada (charged_at, source_type, source_id) — bukan oleh kedisiplinan
     * kode pemanggilnya.
     */
    public function syncCharges(Invoice $invoice): void
    {
        DB::transaction(function () use ($invoice): void {
            $this->syncRegistrationFee($invoice);
            $this->syncPrescriptionCharges($invoice);
            $this->syncOrderCharges($invoice);
            $this->syncProcedureCharges($invoice);
            $this->recalculateTotal($invoice);
            $this->settleIfGuaranteed($invoice);
        });
    }

    /**
     * Pelunasan oleh pasien di kasir.
     *
     * Pengurangan outstanding terjadi dalam satu pernyataan UPDATE bersyarat:
     * dua kasir yang menginput pembayaran pada saat bersamaan tidak bisa
     * keduanya membuat tagihan lunas menjadi lebih bayar — yang kalah
     * balapan mendapat baris terpengaruh nol dan ditolak.
     *
     * @throws BillingException
     */
    public function pay(
        Invoice $invoice,
        float $amount,
        string $method,
        ?User $actor = null,
        ?string $note = null,
    ): Payment {
        if ($invoice->isVoid()) {
            throw new BillingException('Tagihan ini sudah dibatalkan.');
        }

        if (! $invoice->isPatientPayable()) {
            throw new BillingException(
                'Tagihan ini ditanggung penjamin (' . $invoice->payer_name . '), bukan dibayar pasien di kasir.'
            );
        }

        if ($amount <= 0) {
            throw new BillingException('Jumlah pembayaran harus lebih dari nol.');
        }

        return DB::transaction(function () use ($invoice, $amount, $method, $actor, $note): Payment {
            $row = DB::selectOne(
                'UPDATE billing.invoices
                    SET paid_amount = paid_amount + ?, updated_at = now()
                  WHERE id = ? AND (paid_amount + ?) <= total_amount
              RETURNING paid_amount, total_amount',
                [$amount, $invoice->id, $amount]
            );

            if ($row === null) {
                $sisa = $invoice->refresh()->outstanding();

                throw new BillingException(sprintf(
                    'Pembayaran melebihi sisa tagihan. Sisa tagihan Rp %s.',
                    number_format($sisa, 0, ',', '.')
                ));
            }

            $payment = Payment::query()->create([
                'payment_number' => $this->allocatePaymentNumber(),
                'invoice_id' => $invoice->id,
                'amount' => $amount,
                'method' => $method,
                'paid_at' => now(),
                'received_by' => $actor?->id,
                'received_by_name' => $actor?->name,
                'note' => $note,
            ]);

            if ((float) $row->paid_amount >= (float) $row->total_amount) {
                $invoice->update(['status' => Invoice::STATUS_LUNAS, 'closed_at' => now()]);
            }

            return $payment;
        });
    }

    /**
     * Membatalkan pembayaran yang salah input.
     *
     * Baris pembayaran tidak dihapus — hanya ditandai batal — supaya jejak
     * transaksi keuangan tetap utuh.
     *
     * @throws BillingException
     */
    public function voidPayment(Payment $payment, string $reason, ?User $actor = null): Payment
    {
        if ($payment->isVoided()) {
            throw new BillingException('Pembayaran ini sudah dibatalkan sebelumnya.');
        }

        return DB::transaction(function () use ($payment, $reason, $actor): Payment {
            $payment->update([
                'voided_at' => now(),
                'void_reason' => $reason,
                'voided_by' => $actor?->id,
            ]);

            $invoice = $payment->invoice;

            DB::statement(
                'UPDATE billing.invoices SET paid_amount = paid_amount - ?, updated_at = now() WHERE id = ?',
                [(float) $payment->amount, $invoice->id]
            );

            $invoice->refresh();

            if ($invoice->status === Invoice::STATUS_LUNAS && $invoice->outstanding() > 0) {
                $invoice->update(['status' => Invoice::STATUS_TERBUKA, 'closed_at' => null]);
            }

            return $payment->refresh();
        });
    }

    /**
     * @throws BillingException
     */
    public function voidInvoice(Invoice $invoice, string $reason, ?User $actor = null): Invoice
    {
        if ($invoice->isVoid()) {
            throw new BillingException('Tagihan ini sudah dibatalkan.');
        }

        $sudahDibayar = $invoice->payments()->whereNull('voided_at')->exists();

        if ($sudahDibayar) {
            throw new BillingException(
                'Tagihan yang sudah menerima pembayaran tidak bisa dibatalkan. Batalkan pembayarannya lebih dulu.'
            );
        }

        $invoice->update([
            'status' => Invoice::STATUS_VOID,
            'void_reason' => $reason,
            'voided_at' => now(),
        ]);

        return $invoice->refresh();
    }

    private function syncRegistrationFee(Invoice $invoice): void
    {
        $kunjungan = $this->registrations->find($invoice->registration_id);

        if ($kunjungan === null) {
            return;
        }

        DB::statement(
            'INSERT INTO billing.charge_lines
                (charged_at, invoice_id, registration_id, source_type, source_id,
                 description, quantity, unit_price, amount)
             VALUES (?, ?, ?, ?, ?, ?, 1, ?, ?)
             ON CONFLICT (charged_at, source_type, source_id) DO NOTHING',
            [
                $kunjungan->registered_at, $invoice->id, $invoice->registration_id,
                'registrasi', $invoice->registration_id,
                'Biaya Registrasi Rawat Jalan',
                (float) $kunjungan->registration_fee, (float) $kunjungan->registration_fee,
            ]
        );
    }

    private function syncPrescriptionCharges(Invoice $invoice): void
    {
        foreach ($this->prescriptionCharges->forRegistration($invoice->registration_id) as $baris) {
            DB::statement(
                'INSERT INTO billing.charge_lines
                    (charged_at, invoice_id, registration_id, source_type, source_id,
                     description, quantity, unit_price, amount)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
                 ON CONFLICT (charged_at, source_type, source_id) DO NOTHING',
                [
                    $baris->dispensed_at, $invoice->id, $invoice->registration_id,
                    'resep_obat', $baris->item_id,
                    'Obat: ' . $baris->drug_name,
                    (float) $baris->dispensed_quantity, (float) $baris->unit_price, (float) $baris->amount,
                ]
            );
        }
    }

    private function syncOrderCharges(Invoice $invoice): void
    {
        foreach ($this->orderCharges->forRegistration($invoice->registration_id) as $baris) {
            DB::statement(
                'INSERT INTO billing.charge_lines
                    (charged_at, invoice_id, registration_id, source_type, source_id,
                     description, quantity, unit_price, amount)
                 VALUES (?, ?, ?, ?, ?, ?, 1, ?, ?)
                 ON CONFLICT (charged_at, source_type, source_id) DO NOTHING',
                [
                    $baris->verified_at, $invoice->id, $invoice->registration_id,
                    'order_penunjang', $baris->item_id,
                    match ($baris->category) {
                        'lab' => 'Lab: ',
                        'radiologi' => 'Radiologi: ',
                        'pa' => 'PA: ',
                        default => '',
                    } . $baris->test_name,
                    (float) $baris->unit_price, (float) $baris->amount,
                ]
            );
        }
    }

    private function syncProcedureCharges(Invoice $invoice): void
    {
        foreach ($this->procedureCharges->forRegistration($invoice->registration_id) as $baris) {
            DB::statement(
                'INSERT INTO billing.charge_lines
                    (charged_at, invoice_id, registration_id, source_type, source_id,
                     description, quantity, unit_price, amount)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
                 ON CONFLICT (charged_at, source_type, source_id) DO NOTHING',
                [
                    $baris->performed_at, $invoice->id, $invoice->registration_id,
                    'tindakan_ralan', $baris->item_id,
                    'Tindakan: ' . $baris->service_name,
                    (float) $baris->quantity, (float) $baris->unit_price, (float) $baris->amount,
                ]
            );
        }
    }

    private function recalculateTotal(Invoice $invoice): void
    {
        $total = (float) ChargeLine::query()->where('invoice_id', $invoice->id)->sum('amount');

        // total_amount tidak boleh turun di bawah yang sudah dibayar - charge
        // line hanya bertambah seiring pelayanan berjalan, tidak pernah
        // dihapus, jadi ini murni jaga-jaga terhadap balapan sinkronisasi.
        $invoice->update(['total_amount' => max($total, (float) $invoice->paid_amount)]);
    }

    /** Tagihan yang ditanggung penjamin langsung ditutup, tidak menunggu kasir. */
    private function settleIfGuaranteed(Invoice $invoice): void
    {
        if ($invoice->payment_responsibility === Invoice::RESPONSIBILITY_PENJAMIN
            && $invoice->status === Invoice::STATUS_TERBUKA) {
            $invoice->update([
                'status' => Invoice::STATUS_DITANGGUNG_PENJAMIN,
                'closed_at' => now(),
            ]);
        }
    }

    private function allocateNumber(): string
    {
        $prefix = now()->format('Ymd');

        $row = DB::selectOne(
            'INSERT INTO billing.number_sequences (prefix, last_number, updated_at)
             VALUES (?, 1, now())
             ON CONFLICT (prefix) DO UPDATE
                SET last_number = billing.number_sequences.last_number + 1,
                    updated_at  = now()
             RETURNING last_number',
            [$prefix]
        );

        return 'INV' . $prefix . '-' . str_pad((string) $row->last_number, 5, '0', STR_PAD_LEFT);
    }

    private function allocatePaymentNumber(): string
    {
        $prefix = now()->format('Ymd') . '-PAY';

        $row = DB::selectOne(
            'INSERT INTO billing.number_sequences (prefix, last_number, updated_at)
             VALUES (?, 1, now())
             ON CONFLICT (prefix) DO UPDATE
                SET last_number = billing.number_sequences.last_number + 1,
                    updated_at  = now()
             RETURNING last_number',
            [$prefix]
        );

        return 'PAY' . now()->format('Ymd') . '-' . str_pad((string) $row->last_number, 5, '0', STR_PAD_LEFT);
    }
}
