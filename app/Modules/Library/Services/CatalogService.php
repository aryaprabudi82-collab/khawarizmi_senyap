<?php

namespace App\Modules\Library\Services;

use App\Modules\Library\Models\Author;
use App\Modules\Library\Models\Collection as LibraryCollection;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Katalog koleksi perpustakaan (domain Q item A).
 */
class CatalogService
{
    /**
     * Mendaftarkan satu judul.
     *
     * $authorIds urut sitasi: yang pertama adalah pengarang pertama.
     *
     * @param  list<int>  $authorIds
     */
    public function register(array $data, array $authorIds = []): LibraryCollection
    {
        $this->assertMediumSah($data);

        return DB::transaction(function () use ($data, $authorIds) {
            $koleksi = LibraryCollection::query()->create($data + [
                'medium' => LibraryCollection::MEDIUM_CETAK,
                'is_active' => true,
            ]);

            $this->setAuthors($koleksi, $authorIds);

            return $koleksi->load('authors');
        });
    }

    /**
     * Menetapkan pengarang berikut urutannya.
     *
     * Urutan disimpan karena bukan urutan tampilan: pengarang pertama yang
     * dipakai pada sitasi, dan mengurutkannya menurut abjad menghasilkan
     * daftar pustaka yang salah.
     *
     * @param  list<int>  $authorIds
     */
    public function setAuthors(LibraryCollection $collection, array $authorIds): LibraryCollection
    {
        $unik = array_values(array_unique($authorIds));

        if (count($unik) !== count($authorIds)) {
            throw new LibraryException(
                'Satu pengarang tidak bisa dicantumkan dua kali pada judul yang sama.'
            );
        }

        $adaSemua = Author::query()->whereIn('id', $unik)->count() === count($unik);

        if (! $adaSemua) {
            throw new LibraryException('Ada pengarang yang tidak dikenal.');
        }

        $urut = [];
        foreach ($unik as $posisi => $id) {
            $urut[$id] = ['position' => $posisi + 1];
        }

        $collection->authors()->sync($urut);

        return $collection->load('authors');
    }

    /**
     * Pencarian satu pintu untuk SELURUH koleksi.
     *
     * Satu tabel, jadi tidak ada dua sumber yang bisa terlewat. Ini bukan
     * kenyamanan: pencarian yang menggabungkan dua tabel dan lupa salah
     * satunya tetap menghasilkan daftar yang terlihat wajar, hanya saja
     * tanpa separuh koleksi — dan tidak ada yang tahu.
     *
     * @return Collection<int, LibraryCollection>
     */
    public function search(?string $kata = null, ?string $medium = null, ?int $categoryId = null): Collection
    {
        return LibraryCollection::query()
            ->with(['authors', 'publisher', 'category', 'collectionType'])
            ->where('is_active', true)
            ->when($medium !== null, fn ($q) => $q->where('medium', $medium))
            ->when($categoryId !== null, fn ($q) => $q->where('category_id', $categoryId))
            ->when(filled($kata), function ($q) use ($kata) {
                $pola = '%'.mb_strtolower(trim((string) $kata)).'%';

                $q->where(function ($w) use ($pola) {
                    $w->whereRaw('lower(title) like ?', [$pola])
                        ->orWhereRaw('lower(code) like ?', [$pola])
                        ->orWhereRaw('lower(coalesce(isbn, \'\')) like ?', [$pola])
                        ->orWhereExists(function ($sub) use ($pola) {
                            $sub->selectRaw('1')
                                ->from('library.collection_authors as ca')
                                ->join('library.authors as a', 'a.id', '=', 'ca.author_id')
                                ->whereColumn('ca.collection_id', 'library.collections.id')
                                ->whereRaw('lower(a.name) like ?', [$pola]);
                        });
                });
            })
            ->orderBy('title')
            ->get();
    }

    /**
     * Ebook wajib berberkas, cetak tidak boleh.
     *
     * Basis data juga menolaknya; di sini supaya pesannya menjelaskan
     * alasannya, bukan sekadar melanggar constraint.
     */
    private function assertMediumSah(array $data): void
    {
        $medium = $data['medium'] ?? LibraryCollection::MEDIUM_CETAK;
        $berkas = $data['file_path'] ?? null;

        if (! in_array($medium, LibraryCollection::MEDIUM, true)) {
            throw new LibraryException('Medium koleksi "'.$medium.'" tidak dikenal.');
        }

        if ($medium === LibraryCollection::MEDIUM_EBOOK && blank($berkas)) {
            throw new LibraryException(
                'Ebook harus menyertakan berkasnya — entri tanpa berkas ditemukan pemustaka '.
                'di hasil pencarian, dikira tersedia, lalu tidak menghasilkan apa-apa.'
            );
        }

        if ($medium === LibraryCollection::MEDIUM_CETAK && filled($berkas)) {
            throw new LibraryException(
                'Koleksi cetak tidak menyimpan berkas digital; daftarkan sebagai ebook bila memang ada berkasnya.'
            );
        }
    }
}
