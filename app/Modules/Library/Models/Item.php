<?php

namespace App\Modules\Library\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Satu eksemplar fisik.
 *
 * Kolom `condition` menyimpan KONDISI FISIK saja. "Sedang dipinjam"
 * DIHITUNG dari peminjaman yang belum kembali — enum `status_buku` Khanza
 * mencampur keduanya, sehingga buku yang dipinjam lalu kembali dalam
 * keadaan rusak tidak bisa dicatat sebagai keduanya, dan nilai 'Dipinjam'
 * jadi sumber kedua bagi fakta yang sudah dipegang tabel peminjaman.
 */
class Item extends Model
{
    public const KONDISI_BAIK = 'baik';

    public const KONDISI_RUSAK = 'rusak';

    public const KONDISI_HILANG = 'hilang';

    public const KONDISI = [self::KONDISI_BAIK, self::KONDISI_RUSAK, self::KONDISI_HILANG];

    public const ASAL = ['beli', 'hibah', 'bantuan'];

    protected $table = 'library.items';

    protected $guarded = ['id'];

    public function collection(): BelongsTo
    {
        return $this->belongsTo(Collection::class, 'collection_id');
    }

    public function room(): BelongsTo
    {
        return $this->belongsTo(Room::class, 'room_id');
    }

    public function loans(): HasMany
    {
        return $this->hasMany(Loan::class, 'item_id');
    }

    /** Pinjaman yang belum kembali, kalau ada. */
    public function openLoan(): ?Loan
    {
        return $this->loans()->whereNull('returned_at')->first();
    }

    public function sedangDipinjam(): bool
    {
        return $this->loans()->whereNull('returned_at')->exists();
    }

    public function bisaDipinjam(): bool
    {
        return $this->condition === self::KONDISI_BAIK && ! $this->sedangDipinjam();
    }

    protected function casts(): array
    {
        return [
            'acquired_at' => 'date',
            'price' => 'decimal:2',
        ];
    }
}
