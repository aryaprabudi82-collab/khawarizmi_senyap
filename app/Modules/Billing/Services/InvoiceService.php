<?php

namespace App\Modules\Billing\Services;

use App\Modules\Billing\Models\ChargeLine;
use App\Modules\Billing\Models\Invoice;
use App\Modules\Billing\Models\ManualAdjustment;
use App\Modules\Billing\Models\PatientReceivable;
use App\Modules\Billing\Models\Payment;
use App\Modules\Platform\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Pintu masuk konteks billing.
 *
 * Tagihan tidak ditulis manual. Ia dibuka dari satu kunjungan, lalu diisi
 * lewat sinkronisasi charge line dari konteks lain — encounter (biaya
 * registrasi), pharmacy (obat yang diserahkan), order (pemeriksaan lab/
 * radiologi/PA yang selesai), dan clinical (tindakan_ralan dan operasi,
 * menyusul saat domain A digarap berurutan — lihat catatan migrasi
 * clinical.procedures dan clinical.operations).
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
        private readonly OperationChargeContext $operationCharges,
        private readonly RoomChargeContext $roomCharges,
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

        // Tagihan yang sudah dibatalkan sengaja DILEWATI: kunjungan yang
        // tagihannya pernah salah lalu di-void harus tetap bisa ditagih
        // ulang. Indeks uniknya pun parsial (lihat migrasi
        // 2026_10_19_000002), jadi penggantinya sah dibuat.
        $invoice = Invoice::query()
            ->where('registration_id', $registrationId)
            ->where('status', '!=', Invoice::STATUS_VOID)
            ->first();

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
                'care_type' => $kunjungan->care_type,
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
            $this->syncOperationCharges($invoice);
            $this->syncRoomCharges($invoice);
            $this->syncAdjustmentCharges($invoice);
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

        return DB::transaction(function () use ($invoice, $reason): Invoice {
            $invoice->update([
                'status' => Invoice::STATUS_VOID,
                'void_reason' => $reason,
                'voided_at' => now(),
            ]);

            /*
             * Baris biayanya dilepas, bukan ikut disimpan.
             *
             * charge_lines adalah data TURUNAN: ia disintesis ulang dari
             * konteks sumbernya (registrasi, resep, order penunjang,
             * tindakan, operasi, kamar) setiap kali syncCharges() jalan.
             * Kalau baris lama dibiarkan menempel pada tagihan yang sudah
             * void, kunci idempotensinya (charged_at, source_type,
             * source_id) tetap terpakai — sehingga tagihan pengganti untuk
             * kunjungan yang sama akan lahir KOSONG dan pasiennya tetap
             * tidak bisa ditagih. Itu membuat pembatalan jadi jalan buntu
             * yang berbeda, bukan perbaikan.
             *
             * Yang menjadi jejak pembatalan adalah kepala tagihannya:
             * nomor, total yang sempat tercatat, alasan, waktu, dan siapa.
             */
            DB::table('billing.charge_lines')->where('invoice_id', $invoice->id)->delete();

            return $invoice->refresh();
        });
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
                'Biaya Registrasi ' . ($invoice->isRanap() ? 'Rawat Inap' : 'Rawat Jalan'),
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

    /**
     * piutang_pasien — menjadikan sisa tagihan sebagai utang pasien
     * dengan jatuh tempo, mis. pasien pulang tanpa melunasi.
     *
     * Uang muka dicatat lewat pay() biasa, bukan kolom tersendiri, supaya
     * uang muka dan cicilan berikutnya menempuh jalur yang persis sama —
     * dan sisa utangnya selalu sisa tagihan, bukan angka kedua yang bisa
     * berselisih.
     *
     * @throws BillingException
     */
    public function createReceivable(
        Invoice $invoice,
        \DateTimeInterface|string $dueDate,
        User $actor,
        float $downPayment = 0,
        string $downPaymentMethod = 'tunai',
        ?string $note = null,
    ): PatientReceivable {
        if ($invoice->isVoid()) {
            throw new BillingException('Tagihan ini sudah dibatalkan.');
        }

        if (! $invoice->isPatientPayable()) {
            throw new BillingException(
                'Tagihan ini ditanggung penjamin (' . $invoice->payer_name . ') — piutangnya ditagihkan lewat klaim, bukan dijadikan utang pasien.'
            );
        }

        if (PatientReceivable::query()->berlaku()->where('invoice_id', $invoice->id)->exists()) {
            throw new BillingException('Tagihan ini sudah punya piutang yang masih berlaku.');
        }

        $jatuhTempo = \Illuminate\Support\Carbon::parse($dueDate)->startOfDay();

        if ($jatuhTempo->isPast() && ! $jatuhTempo->isToday()) {
            throw new BillingException('Tanggal jatuh tempo tidak boleh sudah lewat.');
        }

        return DB::transaction(function () use ($invoice, $jatuhTempo, $actor, $downPayment, $downPaymentMethod, $note): PatientReceivable {
            if ($downPayment > 0) {
                $this->pay($invoice, $downPayment, $downPaymentMethod, $actor, 'Uang muka piutang pasien');
                $invoice->refresh();
            }

            $sisa = $invoice->outstanding();

            if ($sisa <= 0) {
                throw new BillingException('Tagihan ini sudah lunas, tidak ada sisa yang bisa dijadikan piutang.');
            }

            return PatientReceivable::query()->create([
                'invoice_id' => $invoice->id,
                'registration_id' => $invoice->registration_id,
                'patient_id' => $invoice->patient_id,
                'patient_name' => $invoice->patient_name,
                'care_type' => $invoice->care_type,
                'principal_amount' => $sisa,
                'due_date' => $jatuhTempo->toDateString(),
                'note' => $note,
                'created_by' => $actor->id,
            ]);
        });
    }

    /**
     * Membatalkan piutang yang salah dibuat. Pembayaran yang sudah masuk
     * TIDAK ikut dibatalkan — uang yang sudah diterima tetap uang yang
     * sudah diterima; membatalkannya adalah tindakan tersendiri lewat
     * voidPayment() yang punya jejaknya sendiri.
     */
    public function cancelReceivable(PatientReceivable $piutang, string $reason, User $actor): PatientReceivable
    {
        if ($piutang->isCancelled()) {
            throw new BillingException('Piutang ini sudah dibatalkan.');
        }

        $piutang->update([
            'cancelled_at' => now(),
            'cancelled_by' => $actor->id,
            'cancel_reason' => $reason,
        ]);

        return $piutang->refresh();
    }
    /**
     * Tambahan biaya (tambahan_biaya) atau potongan biaya (potongan_biaya).
     *
     * Potongan disimpan sebagai nilai negatif — dijaga CHECK di basis data,
     * bukan hanya oleh kode ini — supaya penjumlahan total tagihan tetap
     * satu operasi yang sama untuk semua jenis biaya.
     *
     * @throws BillingException
     */
    public function addAdjustment(
        Invoice $invoice,
        string $kind,
        string $description,
        float $amount,
        User $actor,
        ?string $reason = null,
    ): ManualAdjustment {
        if ($invoice->isVoid()) {
            throw new BillingException('Tagihan ini sudah dibatalkan, tidak bisa ditambah penyesuaian.');
        }

        if ($invoice->status === Invoice::STATUS_LUNAS) {
            throw new BillingException('Tagihan ini sudah lunas — batalkan pembayarannya dulu kalau biayanya memang perlu diubah.');
        }

        if (! in_array($kind, [ManualAdjustment::KIND_TAMBAHAN, ManualAdjustment::KIND_POTONGAN], true)) {
            throw new BillingException("Jenis penyesuaian '{$kind}' tidak dikenal.");
        }

        $nilai = abs($amount);

        if ($nilai <= 0) {
            throw new BillingException('Nilai penyesuaian harus lebih dari nol.');
        }

        if ($kind === ManualAdjustment::KIND_POTONGAN) {
            $nilai = -$nilai;

            // Potongan tidak boleh melebihi tagihan yang ada — tagihan minus
            // berarti rumah sakit berutang ke pasien, bukan hasil yang
            // dimaksud siapa pun saat mengetik potongan.
            if ($invoice->total_amount + $nilai < 0) {
                throw new BillingException(sprintf(
                    'Potongan Rp %s melebihi total tagihan Rp %s.',
                    number_format(abs($nilai), 0, ',', '.'),
                    number_format((float) $invoice->total_amount, 0, ',', '.')
                ));
            }
        }

        return DB::transaction(function () use ($invoice, $kind, $description, $nilai, $actor, $reason): ManualAdjustment {
            $penyesuaian = ManualAdjustment::query()->create([
                'invoice_id' => $invoice->id,
                'kind' => $kind,
                'description' => $description,
                'amount' => $nilai,
                'reason' => $reason,
                'created_by' => $actor->id,
            ]);

            $this->syncCharges($invoice);

            return $penyesuaian->refresh();
        });
    }

    /** Membatalkan penyesuaian: baris tagihannya ikut dicabut, bukan sekadar ditandai. */
    public function voidAdjustment(ManualAdjustment $penyesuaian, string $reason, User $actor): ManualAdjustment
    {
        if ($penyesuaian->isVoid()) {
            throw new BillingException('Penyesuaian ini sudah dibatalkan.');
        }

        $invoice = $penyesuaian->invoice;

        if ($invoice->status === Invoice::STATUS_LUNAS) {
            throw new BillingException('Tagihan ini sudah lunas — batalkan pembayarannya dulu.');
        }

        return DB::transaction(function () use ($penyesuaian, $invoice, $reason, $actor): ManualAdjustment {
            $penyesuaian->update([
                'voided_at' => now(),
                'voided_by' => $actor->id,
                'void_reason' => $reason,
            ]);

            $this->syncCharges($invoice);

            return $penyesuaian->refresh();
        });
    }
    /**
     * Biaya kamar rawat inap, satu baris per hari menginap.
     *
     * Kunci idempotensinya jatuh pas tanpa perlakuan khusus: charged_at
     * berbeda tiap hari, jadi (charged_at, 'kamar', admission_id) sudah
     * unik per hari per admisi. Sinkronisasi ulang di hari berikutnya
     * hanya menambah hari yang baru lewat, tidak menggandakan hari lama.
     */
    private function syncRoomCharges(Invoice $invoice): void
    {
        foreach ($this->roomCharges->forRegistration($invoice->registration_id) as $baris) {
            DB::statement(
                'INSERT INTO billing.charge_lines
                    (charged_at, invoice_id, registration_id, source_type, source_id,
                     description, quantity, unit_price, amount)
                 VALUES (?, ?, ?, ?, ?, ?, 1, ?, ?)
                 ON CONFLICT (charged_at, source_type, source_id) DO NOTHING',
                [
                    $baris->charge_date, $invoice->id, $invoice->registration_id,
                    'kamar', $baris->admission_id,
                    'Kamar ' . $baris->room_number . ' (' . $baris->room_class . ') bed ' . $baris->bed_number
                        . ' — ' . \Illuminate\Support\Carbon::parse($baris->charge_date)->format('d-m-Y'),
                    (float) $baris->unit_price, (float) $baris->amount,
                ]
            );
        }
    }

    /** Tambahan & potongan biaya yang diketik kasir (tambahan_biaya, potongan_biaya). */
    private function syncAdjustmentCharges(Invoice $invoice): void
    {
        $berlaku = ManualAdjustment::query()->berlaku()->where('invoice_id', $invoice->id)->get();

        foreach ($berlaku as $penyesuaian) {
            DB::statement(
                'INSERT INTO billing.charge_lines
                    (charged_at, invoice_id, registration_id, source_type, source_id,
                     description, quantity, unit_price, amount)
                 VALUES (?, ?, ?, ?, ?, ?, 1, ?, ?)
                 ON CONFLICT (charged_at, source_type, source_id) DO NOTHING',
                [
                    $penyesuaian->created_at, $invoice->id, $invoice->registration_id,
                    'penyesuaian', $penyesuaian->id,
                    ($penyesuaian->kind === ManualAdjustment::KIND_POTONGAN ? 'Potongan: ' : 'Tambahan: ')
                        . $penyesuaian->description,
                    (float) $penyesuaian->amount, (float) $penyesuaian->amount,
                ]
            );
        }

        // Penyesuaian yang dibatalkan harus hilang dari rincian, bukan
        // sekadar berhenti ditambahkan — baris tagihannya sudah terlanjur
        // ada dari sinkronisasi sebelumnya.
        $dibatalkan = ManualAdjustment::query()
            ->whereNotNull('voided_at')
            ->where('invoice_id', $invoice->id)
            ->pluck('id');

        if ($dibatalkan->isNotEmpty()) {
            DB::table('billing.charge_lines')
                ->where('invoice_id', $invoice->id)
                ->where('source_type', 'penyesuaian')
                ->whereIn('source_id', $dibatalkan)
                ->delete();
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

    private function syncOperationCharges(Invoice $invoice): void
    {
        foreach ($this->operationCharges->forRegistration($invoice->registration_id) as $baris) {
            DB::statement(
                'INSERT INTO billing.charge_lines
                    (charged_at, invoice_id, registration_id, source_type, source_id,
                     description, quantity, unit_price, amount)
                 VALUES (?, ?, ?, ?, ?, ?, 1, ?, ?)
                 ON CONFLICT (charged_at, source_type, source_id) DO NOTHING',
                [
                    $baris->performed_at, $invoice->id, $invoice->registration_id,
                    'operasi', $baris->item_id,
                    'Operasi: ' . $baris->service_name,
                    (float) $baris->amount, (float) $baris->amount,
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
