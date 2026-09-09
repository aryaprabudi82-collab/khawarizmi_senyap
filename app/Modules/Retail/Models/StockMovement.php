<?php

namespace App\Modules\Retail\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StockMovement extends Model
{
    public const MASUK = 'masuk';

    public const KELUAR = 'keluar';

    public const RETUR_MASUK = 'retur-masuk';

    public const RETUR_KELUAR = 'retur-keluar';

    public const KOREKSI = 'koreksi';

    public const RUSAK = 'rusak';

    public const HILANG = 'hilang';

    public const JENIS = [
        self::MASUK, self::KELUAR, self::RETUR_MASUK, self::RETUR_KELUAR,
        self::KOREKSI, self::RUSAK, self::HILANG,
    ];

    /**
     * Arah tiap jenis pergerakan. DITETAPKAN DI SINI, tidak diterima dari
     * pemanggil — aturan yang sama seperti arah kas pada finance dan arah
     * cairan pada clinical: pemanggil yang boleh menentukan arah bisa
     * menambah stok lewat penjualan.
     *
     * `koreksi` tidak ada di sini karena arahnya memang tergantung hasil
     * hitung fisik; ia satu-satunya yang bertanda bebas, dan hanya boleh
     * dibuat lewat penyelesaian stok opname.
     */
    public const ARAH = [
        self::MASUK => 1,
        self::RETUR_MASUK => 1,
        self::KELUAR => -1,
        self::RETUR_KELUAR => -1,
        self::RUSAK => -1,
        self::HILANG => -1,
    ];

    protected $table = 'retail.stock_movements';

    protected $guarded = ['id'];

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'product_id');
    }

    protected function casts(): array
    {
        return ['unit_cost' => 'decimal:2', 'occurred_at' => 'datetime'];
    }
}
