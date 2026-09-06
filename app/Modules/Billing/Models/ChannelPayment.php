<?php

namespace App\Modules\Billing\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Satu pemberitahuan pembayaran dari kanal bank.
 *
 * TIDAK menyimpan uang: nilainya di sini adalah yang DILAPORKAN BANK.
 * Pembayaran yang sah lahir sebagai billing.payments saat dicocokkan, dan
 * baris ini cuma menunjuknya.
 */
class ChannelPayment extends Model
{
    protected $table = 'billing.channel_payments';

    protected $guarded = ['id'];

    public const DITERIMA = 'diterima';
    public const TERCOCOK = 'tercocok';
    public const DITOLAK = 'ditolak';

    protected function casts(): array
    {
        return ['amount' => 'decimal:2', 'paid_at' => 'datetime', 'matched_at' => 'datetime'];
    }

    public function channel(): BelongsTo
    {
        return $this->belongsTo(PaymentChannel::class, 'channel_id');
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }

    public function isMatched(): bool
    {
        return $this->status === self::TERCOCOK;
    }
}
