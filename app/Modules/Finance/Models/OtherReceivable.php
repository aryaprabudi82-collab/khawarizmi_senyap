<?php

namespace App\Modules\Finance\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Piutang di luar pelayanan pasien: jasa perusahaan dan peminjaman uang.
 *
 * Sisa TIDAK disimpan — selalu dihitung dari amount dikurangi jumlah
 * pembayarannya, pola yang sama seperti hutang vendor dan piutang pasien.
 */
class OtherReceivable extends Model
{
    protected $table = 'finance.other_receivables';

    protected $guarded = ['id'];

    public const BERJALAN = 'berjalan';
    public const LUNAS = 'lunas';
    public const DIHAPUSKAN = 'dihapuskan';

    protected function casts(): array
    {
        return [
            'issued_on' => 'date',
            'due_date' => 'date',
            'amount' => 'decimal:2',
            'written_off_at' => 'datetime',
        ];
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(ReceivableCategory::class, 'category_id');
    }

    public function payments(): HasMany
    {
        return $this->hasMany(OtherReceivablePayment::class, 'receivable_id');
    }

    public function paidAmount(): float
    {
        return (float) $this->payments()->sum('amount');
    }

    public function remaining(): float
    {
        return round((float) $this->amount - $this->paidAmount(), 2);
    }
}
