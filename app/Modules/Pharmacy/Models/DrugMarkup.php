<?php

namespace App\Modules\Pharmacy\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Markup harga jual obat untuk satu penjamin (dan opsional satu kelas rawat).
 *
 * Menyatukan `set_harga_obat_ralan` dan `set_harga_obat_ranap` Khanza. Dua
 * tabel untuk satu aturan berarti setiap perhitungan harga harus tahu lebih
 * dulu ia melayani rawat jalan atau rawat inap, lalu membaca tabel yang
 * berbeda — dan yang lupa salah satunya tidak melempar galat, ia jatuh ke
 * harga bawaan dan menagih angka yang salah dengan tenang.
 *
 * Kelas kosong berarti BERLAKU UNTUK SEMUA KELAS, termasuk rawat jalan yang
 * memang tidak punya kelas.
 */
class DrugMarkup extends Model
{
    protected $table = 'pharmacy.drug_markups';

    protected $guarded = ['id'];

    public function berlakuSemuaKelas(): bool
    {
        return $this->room_class === null;
    }

    public function masihBerlaku(): bool
    {
        return $this->effective_until === null;
    }

    /**
     * Markup yang berlaku bagi satu penjamin pada satu tanggal.
     *
     * Yang lebih khusus menang: baris berkelas mengalahkan baris "semua
     * kelas". Kalau tidak, markup ICU yang sengaja ditetapkan berbeda akan
     * tertimpa baris umum yang kebetulan dibuat belakangan.
     *
     * Mengembalikan null kalau belum ada yang ditetapkan — BUKAN nol.
     * Menagih dengan markup nol yang tidak pernah diputuskan berarti
     * menjual obat sesuai harga dasar tanpa ada yang memutuskan begitu.
     */
    public static function berlaku(int $payerId, ?string $roomClass, string $date): ?self
    {
        return static::query()
            ->where('payer_id', $payerId)
            ->whereDate('effective_from', '<=', $date)
            ->where(fn ($q) => $q->whereNull('effective_until')->orWhereDate('effective_until', '>=', $date))
            ->where(fn ($q) => $q->whereNull('room_class')
                ->when($roomClass !== null, fn ($w) => $w->orWhere('room_class', $roomClass)))
            ->orderByRaw('room_class IS NULL')   // yang berkelas lebih dulu
            ->orderByDesc('effective_from')
            ->first();
    }

    protected function casts(): array
    {
        return [
            'markup_percent' => 'decimal:2',
            'effective_from' => 'date',
            'effective_until' => 'date',
        ];
    }
}
