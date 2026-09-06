<?php

namespace App\Modules\Billing\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Satu kanal pembayaran bank.
 *
 * Bank jadi BARIS, bukan nilai enum — menambah bank baru cukup menambah
 * satu baris, tanpa migrasi. Lihat catatan migrasinya.
 */
class PaymentChannel extends Model
{
    protected $table = 'billing.payment_channels';

    protected $guarded = ['id'];

    public const JENIS = ['virtual-account', 'transfer', 'qris', 'edc', 'pihak-ketiga'];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'allows_ralan' => 'boolean',
            'allows_ranap' => 'boolean',
        ];
    }

    /**
     * Metode pembayaran yang dicatat pada billing.payments.
     *
     * billing.payments.method adalah enum tertutup yang menjawab "uangnya
     * bergerak lewat apa" — tunai, debit, kredit, qris, transfer. Kanal
     * menjawab pertanyaan berbeda: "lewat rekening/kerja sama yang mana".
     * Memasukkan kode kanal ke enum itu berarti melebarkannya setiap kali
     * ada bank baru, dan enum yang melebar terus berhenti berarti apa-apa.
     *
     * Jadi kanal DIPETAKAN ke metode yang sudah bermakna, dan identitas
     * kanalnya tetap terbaca lewat billing.channel_payments yang menunjuk
     * pembayaran itu. Tidak ada informasi yang hilang.
     */
    public function paymentMethod(): string
    {
        return match ($this->kind) {
            'qris' => 'qris',
            'edc' => 'debit',
            default => 'transfer',
        };
    }

    /** set_tarif_online: apakah kanal ini boleh dipakai untuk jenis rawat tertentu. */
    public function allows(string $careType): bool
    {
        return $careType === 'ranap' ? $this->allows_ranap : $this->allows_ralan;
    }
}
