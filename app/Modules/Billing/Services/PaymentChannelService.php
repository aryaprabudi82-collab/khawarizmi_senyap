<?php

namespace App\Modules\Billing\Services;

use App\Modules\Billing\Models\ChannelPayment;
use App\Modules\Billing\Models\Invoice;
use App\Modules\Billing\Models\PaymentChannel;
use App\Modules\Platform\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Kanal pembayaran bank (domain K item G) — 7 kode.
 *
 *   channels / saveChannel -> set_tarif_online dan master kanalnya
 *   receive                -> pemberitahuan masuk dari tiap bank
 *   match                  -> pencocokan ke tagihan
 *   inbox / recap          -> pembayaran_bank_* dan pembayaran_briva
 *
 * SATU MEKANISME untuk semua bank. Yang berbeda antar bank cuma cara data
 * itu masuk; begitu sudah masuk, semuanya sejumlah uang dengan nomor
 * rujukan yang harus dicocokkan ke tagihan.
 *
 * KELAS INI TIDAK MENCATAT UANG SENDIRI. Pencocokan memanggil
 * InvoiceService::pay() — pembayaran kanal jadi billing.payments persis
 * seperti pembayaran di kasir, cuma methodnya berbeda. Menyimpan nilainya
 * di dua tempat akan melahirkan dua angka yang bisa berbeda; pelajaran
 * yang sama sudah dibayar di item D dan item F.
 */
class PaymentChannelService
{
    public function __construct(private readonly InvoiceService $invoices) {}

    // ---------------------------------------------------------------- kanal

    /**
     * @throws BillingException
     */
    public function saveChannel(array $data, ?PaymentChannel $channel = null): PaymentChannel
    {
        if (! in_array($data['kind'] ?? '', PaymentChannel::JENIS, true)) {
            throw new BillingException('Jenis kanal tidak dikenal.');
        }

        $kode = trim($data['code'] ?? '');

        $ganda = PaymentChannel::query()
            ->where('code', $kode)
            ->when($channel, fn ($q) => $q->whereKeyNot($channel->id))
            ->exists();

        if ($ganda) {
            throw new BillingException("Kanal dengan kode {$kode} sudah ada.");
        }

        $isi = [
            'code' => $kode,
            'name' => $data['name'],
            'kind' => $data['kind'],
            'bank_name' => $data['bank_name'] ?? null,
            'account_number' => $data['account_number'] ?? null,
            'is_active' => $data['is_active'] ?? true,
            'allows_ralan' => $data['allows_ralan'] ?? true,
            'allows_ranap' => $data['allows_ranap'] ?? true,
        ];

        if ($channel) {
            $channel->update($isi);

            return $channel->refresh();
        }

        return PaymentChannel::query()->create($isi);
    }

    // ------------------------------------------------------------- inbox

    /**
     * Mencatat satu pemberitahuan pembayaran dari bank.
     *
     * @throws BillingException
     */
    public function receive(PaymentChannel $channel, array $data, ?int $actorId = null): ChannelPayment
    {
        if (! $channel->is_active) {
            throw new BillingException("Kanal {$channel->name} sudah tidak aktif.");
        }

        $nilai = round((float) ($data['amount'] ?? 0), 2);

        if ($nilai <= 0) {
            throw new BillingException('Nilai pembayaran harus lebih dari nol.');
        }

        $rujukan = trim($data['reference_number'] ?? '');

        if ($rujukan === '') {
            throw new BillingException('Nomor rujukan bank wajib diisi.');
        }

        // Berkas rekening koran lazim diunggah berulang; tanpa penjagaan
        // ini pembayaran yang sama tercatat dua kali dan tagihan pasien
        // terlihat lunas berlebih.
        $sudahAda = ChannelPayment::query()
            ->where('channel_id', $channel->id)
            ->where('reference_number', $rujukan)
            ->where('status', '<>', ChannelPayment::DITOLAK)
            ->exists();

        if ($sudahAda) {
            throw new BillingException("Rujukan {$rujukan} dari kanal ini sudah pernah diterima.");
        }

        return ChannelPayment::query()->create([
            'channel_id' => $channel->id,
            'reference_number' => $rujukan,
            'virtual_account' => $data['virtual_account'] ?? null,
            'payer_name' => $data['payer_name'] ?? null,
            'amount' => $nilai,
            'paid_at' => $data['paid_at'],
            'status' => ChannelPayment::DITERIMA,
            'recorded_by' => $actorId,
        ]);
    }

    /**
     * Mencocokkan pemberitahuan ke satu tagihan, lalu mencatat
     * pembayarannya lewat mekanisme kasir yang sudah ada.
     *
     * @throws BillingException
     */
    public function match(ChannelPayment $masuk, Invoice $invoice, ?User $actor = null): ChannelPayment
    {
        if ($masuk->status !== ChannelPayment::DITERIMA) {
            throw new BillingException('Hanya pemberitahuan yang masih baru yang bisa dicocokkan.');
        }

        $channel = $masuk->channel;

        if (! $channel->allows($invoice->care_type ?? 'ralan')) {
            throw new BillingException(
                "Kanal {$channel->name} tidak diizinkan untuk tagihan {$invoice->care_type}."
            );
        }

        $sisa = $invoice->outstanding();

        if ($sisa <= 0) {
            throw new BillingException('Tagihan ini sudah tidak punya sisa.');
        }

        if ((float) $masuk->amount > $sisa) {
            throw new BillingException(
                'Nilai pembayaran melebihi sisa tagihan (sisa ' . number_format($sisa, 2, ',', '.') . ').'
            );
        }

        return DB::transaction(function () use ($masuk, $invoice, $channel, $actor) {
            // Pembayarannya dicatat lewat InvoiceService seperti pembayaran
            // di kasir — bukan disalin ke tabel ini.
            $bayar = $this->invoices->pay(
                $invoice,
                (float) $masuk->amount,
                // Metode diambil dari JENIS kanalnya, bukan kodenya:
                // billing.payments.method menjawab "uangnya bergerak lewat
                // apa", dan identitas kanalnya tetap terbaca lewat baris
                // channel_payments yang menunjuk pembayaran ini.
                $channel->paymentMethod(),
                $actor,
                'Kanal ' . $channel->name . ' (' . $channel->code . ') rujukan ' . $masuk->reference_number,
            );

            $masuk->update([
                'status' => ChannelPayment::TERCOCOK,
                'invoice_id' => $invoice->id,
                'payment_id' => $bayar->id,
                'matched_at' => now(),
                'matched_by' => $actor?->id,
            ]);

            return $masuk->refresh();
        });
    }

    /**
     * @throws BillingException
     */
    public function reject(ChannelPayment $masuk, string $reason, ?int $actorId = null): ChannelPayment
    {
        if ($masuk->isMatched()) {
            throw new BillingException('Pemberitahuan yang sudah dicocokkan tidak bisa ditolak.');
        }

        $masuk->update([
            'status' => ChannelPayment::DITOLAK,
            'rejection_reason' => $reason,
            'matched_by' => $actorId,
            'matched_at' => now(),
        ]);

        return $masuk->refresh();
    }

    // ---------------------------------------------------------------- laporan

    /** Pemberitahuan yang belum dicocokkan — pekerjaan yang belum selesai. */
    public function unmatched(?int $channelId = null): Collection
    {
        return $this->inboxQuery($channelId, ChannelPayment::DITERIMA)->get();
    }

    public function inbox(?int $channelId = null, ?string $status = null): Collection
    {
        return $this->inboxQuery($channelId, $status)->limit(200)->get();
    }

    /** Rekap per kanal: berapa masuk, berapa tercocok, berapa menggantung. */
    public function recapByChannel(string $from, string $until): Collection
    {
        return DB::table('billing.channel_payments as p')
            ->join('billing.payment_channels as k', 'k.id', '=', 'p.channel_id')
            ->whereRaw('p.paid_at::date BETWEEN ?::date AND ?::date', [$from, $until])
            ->groupBy('k.name', 'k.code')
            ->selectRaw("k.code, k.name,
                         count(*) AS pemberitahuan,
                         count(*) FILTER (WHERE p.status = 'tercocok') AS tercocok,
                         count(*) FILTER (WHERE p.status = 'diterima') AS menggantung,
                         count(*) FILTER (WHERE p.status = 'ditolak') AS ditolak,
                         coalesce(sum(p.amount) FILTER (WHERE p.status = 'tercocok'), 0) AS nilai_tercocok,
                         coalesce(sum(p.amount) FILTER (WHERE p.status = 'diterima'), 0) AS nilai_menggantung")
            ->orderBy('k.code')
            ->get();
    }

    public function channels(bool $activeOnly = false): Collection
    {
        return PaymentChannel::query()
            ->when($activeOnly, fn ($q) => $q->where('is_active', true))
            ->orderBy('code')
            ->get();
    }

    private function inboxQuery(?int $channelId, ?string $status)
    {
        $q = DB::table('billing.channel_payments as p')
            ->join('billing.payment_channels as k', 'k.id', '=', 'p.channel_id')
            ->leftJoin('billing.invoices as i', 'i.id', '=', 'p.invoice_id')
            ->selectRaw('p.*, k.name AS channel_name, k.code AS channel_code, i.invoice_number')
            ->orderByDesc('p.paid_at');

        if ($channelId !== null) {
            $q->where('p.channel_id', $channelId);
        }

        if ($status !== null && $status !== '') {
            $q->where('p.status', $status);
        }

        return $q;
    }
}
