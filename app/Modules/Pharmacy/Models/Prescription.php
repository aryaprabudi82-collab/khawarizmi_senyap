<?php

namespace App\Modules\Pharmacy\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Prescription extends Model
{
    use SoftDeletes;

    public const STATUS_DITULIS = 'ditulis';
    public const STATUS_MENUNGGU_TELAAH = 'menunggu-telaah';
    public const STATUS_DISETUJUI = 'disetujui';
    public const STATUS_DITOLAK = 'ditolak';
    public const STATUS_DISERAHKAN = 'diserahkan';
    public const STATUS_BATAL = 'batal';

    protected $table = 'pharmacy.prescriptions';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'prescribed_at' => 'datetime',
            'submitted_at' => 'datetime',
            'reviewed_at' => 'datetime',
            'dispensed_at' => 'datetime',
            'total_amount' => 'decimal:2',
        ];
    }

    public function items(): HasMany
    {
        return $this->hasMany(PrescriptionItem::class);
    }

    public function reviews(): HasMany
    {
        return $this->hasMany(PrescriptionReview::class)->orderByDesc('reviewed_at');
    }

    /** Masih boleh diubah isinya. */
    public function isEditable(): bool
    {
        return in_array($this->status, [self::STATUS_DITULIS, self::STATUS_DITOLAK], true);
    }

    public function isDispensable(): bool
    {
        return $this->status === self::STATUS_DISETUJUI;
    }

    public static function statusLabel(string $status): string
    {
        return match ($status) {
            self::STATUS_DITULIS => 'Ditulis dokter',
            self::STATUS_MENUNGGU_TELAAH => 'Menunggu telaah apoteker',
            self::STATUS_DISETUJUI => 'Disetujui, siap diserahkan',
            self::STATUS_DITOLAK => 'Ditolak apoteker',
            self::STATUS_DISERAHKAN => 'Sudah diserahkan',
            self::STATUS_BATAL => 'Dibatalkan',
            default => $status,
        };
    }
}
