<?php

namespace App\Modules\Library\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * Satu judul koleksi — cetak maupun ebook.
 *
 * Ebook adalah MEDIUM, bukan tabel kedua: `perpustakaan_ebook` Khanza
 * menyalin `perpustakaan_buku` nyaris kolom per kolom, dan dua tabel untuk
 * satu hal membuat setiap pencarian harus menggabungkan keduanya —
 * pencarian yang lupa salah satunya menjawab dengan tenang tanpa separuh
 * koleksi.
 */
class Collection extends Model
{
    public const MEDIUM_CETAK = 'cetak';

    public const MEDIUM_EBOOK = 'ebook';

    public const MEDIUM = [self::MEDIUM_CETAK, self::MEDIUM_EBOOK];

    protected $table = 'library.collections';

    protected $guarded = ['id'];

    public function authors(): BelongsToMany
    {
        return $this->belongsToMany(Author::class, 'library.collection_authors', 'collection_id', 'author_id')
            ->withPivot('position')
            ->orderByPivot('position');
    }

    public function publisher(): BelongsTo
    {
        return $this->belongsTo(Publisher::class, 'publisher_id');
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class, 'category_id');
    }

    public function collectionType(): BelongsTo
    {
        return $this->belongsTo(CollectionType::class, 'collection_type_id');
    }

    public function isEbook(): bool
    {
        return $this->medium === self::MEDIUM_EBOOK;
    }

    /**
     * Pengarang urut sitasi, bukan urut abjad — pengarang pertama yang
     * dipakai pada daftar pustaka, dan mengurutkannya menurut abjad
     * menghasilkan sitasi yang salah.
     */
    public function penulisTerurut(): string
    {
        return $this->authors
            ->map(fn (Author $a) => $a->citation_name ?: $a->name)
            ->implode('; ');
    }

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }
}
