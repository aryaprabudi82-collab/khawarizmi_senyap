<?php

namespace App\Modules\Library\Http\Controllers;

use App\Modules\Library\Models\Author;
use App\Modules\Library\Models\Category;
use App\Modules\Library\Models\Collection as LibraryCollection;
use App\Modules\Library\Models\CollectionType;
use App\Modules\Library\Models\Publisher;
use App\Modules\Library\Models\Room;
use App\Modules\Library\Services\CatalogService;
use App\Modules\Library\Services\LibraryException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class CatalogController
{
    public function __construct(private readonly CatalogService $catalog) {}

    public function index(Request $request): View
    {
        return view('library::koleksi.index', [
            'koleksi' => $this->catalog->search(
                $request->query('q'),
                $request->query('medium'),
                $request->query('kategori') !== null ? (int) $request->query('kategori') : null
            ),
            'kategori' => Category::query()->where('is_active', true)->orderBy('name')->get(),
            'jenis' => CollectionType::query()->where('is_active', true)->orderBy('name')->get(),
            'penerbit' => Publisher::query()->where('is_active', true)->orderBy('name')->get(),
            'pengarang' => Author::query()->where('is_active', true)->orderBy('name')->get(),
            'filter' => [
                'q' => $request->query('q'),
                'medium' => $request->query('medium'),
                'kategori' => $request->query('kategori'),
            ],
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'max:20', 'unique:App\Modules\Library\Models\Collection,code'],
            'title' => ['required', 'string', 'max:250'],
            'medium' => ['required', Rule::in(LibraryCollection::MEDIUM)],
            'publisher_id' => ['nullable', 'integer', 'exists:App\Modules\Library\Models\Publisher,id'],
            'category_id' => ['nullable', 'integer', 'exists:App\Modules\Library\Models\Category,id'],
            'collection_type_id' => ['nullable', 'integer', 'exists:App\Modules\Library\Models\CollectionType,id'],
            'publication_year' => ['nullable', 'integer', 'between:1400,2200'],
            'page_count' => ['nullable', 'integer', 'min:1'],
            'edition' => ['nullable', 'string', 'max:40'],
            'isbn' => ['nullable', 'string', 'max:20', 'unique:App\Modules\Library\Models\Collection,isbn'],
            'file_path' => ['nullable', 'string', 'max:500'],
            'abstract' => ['nullable', 'string', 'max:4000'],
            'authors' => ['nullable', 'array'],
            'authors.*' => ['integer', 'exists:App\Modules\Library\Models\Author,id'],
        ], [], [
            'code' => 'nomor panggil', 'title' => 'judul', 'medium' => 'medium',
            'isbn' => 'ISBN', 'file_path' => 'berkas ebook', 'authors' => 'pengarang',
        ]);

        $pengarang = array_map('intval', $data['authors'] ?? []);
        unset($data['authors']);

        // Kolom yang tidak berlaku dikosongkan supaya string kosong dari
        // formulir tidak lolos jadi nilai.
        if ($data['medium'] === LibraryCollection::MEDIUM_CETAK) {
            $data['file_path'] = null;
        }

        try {
            $koleksi = $this->catalog->register($data, $pengarang);
        } catch (LibraryException $e) {
            return back()->withInput()->with('galat', $e->getMessage());
        }

        return back()->with('sukses', 'Koleksi "'.$koleksi->title.'" terdaftar.');
    }

    // ------------------------------------------------------------ master

    public function master(): View
    {
        return view('library::master.index', [
            'ruang' => Room::query()->orderBy('code')->get(),
            'kategori' => Category::query()->orderBy('code')->get(),
            'jenis' => CollectionType::query()->orderBy('code')->get(),
            'penerbit' => Publisher::query()->orderBy('code')->get(),
            'pengarang' => Author::query()->orderBy('code')->get(),
        ]);
    }

    public function storeMaster(Request $request): RedirectResponse
    {
        $jenisMaster = [
            'ruang' => Room::class,
            'kategori' => Category::class,
            'jenis' => CollectionType::class,
            'penerbit' => Publisher::class,
            'pengarang' => Author::class,
        ];

        $data = $request->validate([
            'master' => ['required', Rule::in(array_keys($jenisMaster))],
            'code' => ['required', 'string', 'max:20'],
            'name' => ['required', 'string', 'max:150'],
            'citation_name' => ['nullable', 'string', 'max:150'],
        ], [], ['master' => 'jenis master', 'code' => 'kode', 'name' => 'nama', 'citation_name' => 'nama sitasi']);

        /** @var class-string<Model> $model */
        $model = $jenisMaster[$data['master']];

        if ($model::query()->where('code', $data['code'])->exists()) {
            return back()->withInput()->with('galat', 'Kode "'.$data['code'].'" sudah dipakai pada master itu.');
        }

        $isi = ['code' => $data['code'], 'name' => $data['name'], 'is_active' => true];

        // Nama sitasi hanya ada pada pengarang: pembalikan nama tidak bisa
        // ditebak dari nama lengkap, dan menebak salah membuat daftar
        // pustaka keliru.
        if ($data['master'] === 'pengarang') {
            $isi['citation_name'] = $data['citation_name'] ?? null;
        }

        $model::query()->create($isi);

        return back()->with('sukses', 'Data master "'.$data['name'].'" ditambahkan.');
    }
}
