<?php

namespace App\Modules\Inventory\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Pengajuan penghapusan stok — barang rusak, kedaluwarsa, atau hilang.
 *
 * MENUNTUT PERSETUJUAN ORANG LAIN. Membuang barang adalah peristiwa
 * keuangan: nilainya hilang dari neraca. Yang mencatat boleh petugas
 * gudang, yang menyetujui harus orang lain — pemisahan itu yang menahan
 * stok hilang dicatat sebagai "rusak".
 */
class StockWriteOff extends Model
{
    protected $table = 'inventory.stock_write_offs';

    protected $guarded = ['id'];

    public const DIAJUKAN = 'diajukan';

    public const DISETUJUI = 'disetujui';

    public const DITOLAK = 'ditolak';

    public const RUSAK = 'rusak';

    public const KEDALUWARSA = 'kedaluwarsa';

    public const HILANG = 'hilang';

    /**
     * Alasan penghapusan, dan ketiganya sengaja dibedakan.
     *
     * Rusak menunjuk masalah penyimpanan atau penanganan; kedaluwarsa
     * menunjuk masalah perencanaan pembelian; hilang menunjuk masalah
     * pengamanan. Jawaban atas "kenapa kita banyak membuang" menentukan
     * tindakan yang sama sekali berbeda, dan menyatukan ketiganya
     * membuat pertanyaannya tidak bisa dijawab.
     */
    public const ALASAN = [
        self::RUSAK => 'Rusak',
        self::KEDALUWARSA => 'Kedaluwarsa',
        self::HILANG => 'Hilang',
    ];

    protected function casts(): array
    {
        return [
            'write_off_date' => 'date',
            'decided_at' => 'datetime',
            'total_value' => 'decimal:2',
        ];
    }

    public function items(): HasMany
    {
        return $this->hasMany(StockWriteOffItem::class, 'write_off_id');
    }

    public function isPending(): bool
    {
        return $this->status === self::DIAJUKAN;
    }

    public function isApproved(): bool
    {
        return $this->status === self::DISETUJUI;
    }

    /**
     * Nilai yang dihitung dari barisnya.
     *
     * Dipakai MEMBANDINGKAN dengan total_value yang dibekukan, bukan
     * menggantikannya: yang beku adalah kerugian pada saat penghapusan,
     * dan yang dihitung ulang di sini memakai harga yang sama karena
     * harganya pun ikut dibekukan per baris.
     */
    public function computedValue(): float
    {
        return round((float) $this->items()->sum('amount'), 2);
    }
}
