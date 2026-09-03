<?php

namespace App\Modules\Envlab\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SampleTest extends Model
{
    public const STATUS_DIMINTA = 'diminta';
    public const STATUS_DITOLAK = 'ditolak';
    public const STATUS_DIPROSES = 'diproses';
    public const STATUS_HASIL_TERSEDIA = 'hasil-tersedia';
    public const STATUS_TERVERIFIKASI = 'terverifikasi';
    public const STATUS_SELESAI = 'selesai';
    public const STATUS_DIBATALKAN = 'dibatalkan';

    public const PAYMENT_BELUM_BAYAR = 'belum-bayar';
    public const PAYMENT_LUNAS = 'lunas';

    protected $table = 'envlab.sample_tests';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'price' => 'decimal:2',
            'requested_at' => 'datetime',
            'assigned_at' => 'datetime',
            'verified_at' => 'datetime',
            'validated_at' => 'datetime',
            'paid_at' => 'datetime',
        ];
    }

    public function items(): HasMany
    {
        return $this->hasMany(SampleTestItem::class, 'sample_test_id');
    }

    public static function statusLabel(string $status): string
    {
        return match ($status) {
            self::STATUS_DIMINTA => 'Diminta',
            self::STATUS_DITOLAK => 'Tidak Dapat Dilayani',
            self::STATUS_DIPROSES => 'Diproses',
            self::STATUS_HASIL_TERSEDIA => 'Hasil Tersedia',
            self::STATUS_TERVERIFIKASI => 'Terverifikasi',
            self::STATUS_SELESAI => 'Selesai (Tervalidasi)',
            self::STATUS_DIBATALKAN => 'Dibatalkan',
            default => $status,
        };
    }
}
