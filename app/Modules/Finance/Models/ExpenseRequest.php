<?php

namespace App\Modules\Finance\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Satu pengajuan biaya, dari diajukan sampai dicairkan.
 *
 * Nilai yang DISETUJUI disimpan terpisah dari yang DIAJUKAN — lihat
 * catatan migrasinya untuk alasannya.
 */
class ExpenseRequest extends Model
{
    protected $table = 'finance.expense_requests';

    protected $guarded = ['id'];

    public const DIAJUKAN = 'diajukan';
    public const DISETUJUI = 'disetujui';
    public const DITOLAK = 'ditolak';
    public const TERVALIDASI = 'tervalidasi';
    public const DICAIRKAN = 'dicairkan';
    public const DIBATALKAN = 'dibatalkan';

    /** Urutan tahap yang sah; dipakai service untuk menolak lompatan. */
    public const ALUR = [
        self::DIAJUKAN => [self::DISETUJUI, self::DITOLAK, self::DIBATALKAN],
        self::DISETUJUI => [self::TERVALIDASI, self::DITOLAK],
        self::TERVALIDASI => [self::DICAIRKAN],
    ];

    protected function casts(): array
    {
        return [
            'requested_on' => 'date',
            'requested_amount' => 'decimal:2',
            'approved_amount' => 'decimal:2',
            'approved_at' => 'datetime',
            'validated_at' => 'datetime',
            'disbursed_at' => 'datetime',
        ];
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(CashCategory::class, 'category_id');
    }

    /** Selisih antara yang diminta dan yang disetujui — angka yang dicari saat menyusun anggaran. */
    public function reduction(): float
    {
        if ($this->approved_amount === null) {
            return 0.0;
        }

        return round((float) $this->requested_amount - (float) $this->approved_amount, 2);
    }

    public function canAdvanceTo(string $status): bool
    {
        return in_array($status, self::ALUR[$this->status] ?? [], true);
    }
}
