<?php

namespace App\Modules\Order\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Nama kelas sengaja bukan "Order" — kata itu kata kunci di banyak konteks
 * dan gampang bentrok dengan Eloquent atau query builder. Tabelnya tetap
 * orders.orders sesuai schema.
 */
class LabRadiologyOrder extends Model
{
    use SoftDeletes;

    public const CATEGORY_LAB = 'lab';
    public const CATEGORY_RADIOLOGI = 'radiologi';
    public const CATEGORY_PA = 'pa';

    public const STATUS_DIMINTA = 'diminta';
    public const STATUS_DIPROSES = 'diproses';
    public const STATUS_HASIL_TERSEDIA = 'hasil-tersedia';
    public const STATUS_SELESAI = 'selesai';
    public const STATUS_BATAL = 'batal';

    protected $table = 'orders.orders';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'requested_at' => 'datetime',
            'processed_at' => 'datetime',
            'resulted_at' => 'datetime',
            'verified_at' => 'datetime',
        ];
    }

    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class, 'order_id');
    }

    public function isEditable(): bool
    {
        return $this->status === self::STATUS_DIMINTA;
    }

    public function isVerifiable(): bool
    {
        return $this->status === self::STATUS_HASIL_TERSEDIA;
    }

    public static function statusLabel(string $status): string
    {
        return match ($status) {
            self::STATUS_DIMINTA => 'Diminta',
            self::STATUS_DIPROSES => 'Diproses',
            self::STATUS_HASIL_TERSEDIA => 'Hasil tersedia',
            self::STATUS_SELESAI => 'Selesai (terverifikasi)',
            self::STATUS_BATAL => 'Dibatalkan',
            default => $status,
        };
    }

    public static function categoryLabel(string $category): string
    {
        return match ($category) {
            self::CATEGORY_LAB => 'Laboratorium',
            self::CATEGORY_RADIOLOGI => 'Radiologi',
            self::CATEGORY_PA => 'Patologi Anatomi',
            default => $category,
        };
    }
}
