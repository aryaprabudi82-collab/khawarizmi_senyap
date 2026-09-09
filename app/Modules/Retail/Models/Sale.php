<?php

namespace App\Modules\Retail\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Satu penjualan — tunai maupun piutang.
 *
 * Khanza memisahkannya jadi `tokopenjualan` dan `tokopiutang`. Dua tabel
 * untuk satu peristiwa berarti setiap laporan penjualan harus
 * menggabungkan keduanya, dan laporan yang lupa salah satunya MENJAWAB
 * DENGAN TENANG dengan angka yang lebih kecil daripada kenyataannya.
 */
class Sale extends Model
{
    public const TUNAI = 'tunai';

    public const PIUTANG = 'piutang';

    public const CARA_BAYAR = [self::TUNAI, self::PIUTANG];

    public const LUNAS = 'lunas';

    public const SEBAGIAN = 'sebagian';

    public const BELUM = 'belum';

    protected $table = 'retail.sales';

    protected $guarded = ['id'];

    public function items(): HasMany
    {
        return $this->hasMany(SaleItem::class, 'sale_id');
    }

    public function payments(): HasMany
    {
        return $this->hasMany(SalePayment::class, 'sale_id');
    }

    public function returns(): HasMany
    {
        return $this->hasMany(SaleReturn::class, 'sale_id');
    }

    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class, 'member_id');
    }

    public function priceTier(): BelongsTo
    {
        return $this->belongsTo(PriceTier::class, 'price_tier_id');
    }

    /**
     * Sisa piutang. DIHITUNG — `tokopiutang.sisapiutang` Khanza adalah
     * saldo yang menempel pada notanya, dan saldo tanpa buku pembayaran
     * akan melenceng begitu satu cicilan gagal di tengah.
     *
     * Retur atas penjualan piutang ikut mengurangi tagihan, bukan
     * mengeluarkan kas.
     */
    public function sisaPiutang(): float
    {
        if ($this->payment_type !== self::PIUTANG) {
            return 0.0;
        }

        $dibayar = (float) $this->payments()->sum('amount');
        $diretur = (float) $this->returns()->where('refunded_in_cash', false)->sum('total_amount');

        return round(max(0, (float) $this->total - $dibayar - $diretur), 2);
    }

    public function terlambatBayar(): bool
    {
        return $this->payment_type === self::PIUTANG
            && $this->payment_status !== self::LUNAS
            && $this->due_on !== null
            && $this->due_on->isBefore(now()->startOfDay());
    }

    /**
     * Jumlah per produk yang masih bisa diretur. DIHITUNG dari selisih
     * yang dijual dan yang sudah diretur.
     *
     * @return array<int, int>
     */
    public function sisaBisaDiretur(): array
    {
        $dijual = $this->items->pluck('quantity', 'product_id')->map(fn ($n) => (int) $n)->all();

        $diretur = [];
        foreach ($this->returns as $retur) {
            foreach ($retur->items as $baris) {
                $diretur[$baris->product_id] = ($diretur[$baris->product_id] ?? 0) + (int) $baris->quantity;
            }
        }

        $sisa = [];
        foreach ($dijual as $produkId => $jumlah) {
            $sisa[$produkId] = $jumlah - ($diretur[$produkId] ?? 0);
        }

        return $sisa;
    }

    protected function casts(): array
    {
        return [
            'sold_at' => 'datetime',
            'due_on' => 'date',
            'subtotal' => 'decimal:2',
            'discount' => 'decimal:2',
            'total' => 'decimal:2',
            'down_payment' => 'decimal:2',
        ];
    }
}
