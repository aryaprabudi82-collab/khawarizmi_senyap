<?php

namespace App\Modules\Finance\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Satu faktur vendor yang terutang.
 *
 * amount DIBEKUKAN saat validasi. Sisa hutang TIDAK disimpan — selalu
 * dihitung dari amount dikurangi jumlah pembayarannya. Lihat catatan
 * migrasinya untuk alasan kedua keputusan itu.
 */
class Payable extends Model
{
    protected $table = 'finance.payables';

    protected $guarded = ['id'];

    public const DITITIPKAN = 'dititipkan';
    public const TERVALIDASI = 'tervalidasi';
    public const DITOLAK = 'ditolak';
    public const LUNAS = 'lunas';

    /** Rantai pengadaan asal barangnya. */
    public const SUMBER = [
        'farmasi' => 'Obat & BHP',
        'non-medis' => 'Barang Non-Medis',
        'dapur' => 'Barang Dapur',
        'aset' => 'Aset & Inventaris',
    ];

    protected function casts(): array
    {
        return [
            'invoice_date' => 'date',
            'due_date' => 'date',
            'amount' => 'decimal:2',
            'validated_at' => 'datetime',
        ];
    }

    public function payments(): HasMany
    {
        return $this->hasMany(PayablePayment::class, 'payable_id');
    }

    /** Jumlah yang sudah dibayar — dihitung, tidak disimpan. */
    public function paidAmount(): float
    {
        return (float) $this->payments()->sum('amount');
    }

    /** Sisa hutang — dihitung, tidak disimpan. */
    public function remaining(): float
    {
        return round((float) $this->amount - $this->paidAmount(), 2);
    }

    public function isValidated(): bool
    {
        return in_array($this->status, [self::TERVALIDASI, self::LUNAS], true);
    }
}
