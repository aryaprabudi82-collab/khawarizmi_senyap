<?php

namespace App\Modules\Library\Http\Controllers;

use App\Modules\Library\Models\Collection as LibraryCollection;
use App\Modules\Library\Models\Fine;
use App\Modules\Library\Models\FineType;
use App\Modules\Library\Models\Item;
use App\Modules\Library\Models\Loan;
use App\Modules\Library\Models\LoanPolicy;
use App\Modules\Library\Models\Member;
use App\Modules\Library\Models\Room;
use App\Modules\Library\Services\CirculationService;
use App\Modules\Library\Services\LibraryException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class CirculationController
{
    public function __construct(private readonly CirculationService $sirkulasi) {}

    // ------------------------------------------------------- eksemplar

    public function items(): View
    {
        return view('library::eksemplar.index', [
            'eksemplar' => Item::query()->with(['collection', 'room'])->orderBy('inventory_number')->limit(200)->get(),
            // Hanya koleksi CETAK: ebook tidak punya eksemplar fisik, dan
            // menawarkannya di sini akan melahirkan eksemplar yang tidak
            // pernah bisa dipinjamkan.
            'koleksi' => LibraryCollection::query()
                ->where('medium', LibraryCollection::MEDIUM_CETAK)
                ->where('is_active', true)->orderBy('title')->get(),
            'ruang' => Room::query()->where('is_active', true)->orderBy('name')->get(),
            'kondisi' => Item::KONDISI,
            'asal' => Item::ASAL,
        ]);
    }

    public function storeItem(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'inventory_number' => ['required', 'string', 'max:30', 'unique:App\Modules\Library\Models\Item,inventory_number'],
            'collection_id' => ['required', 'integer', 'exists:App\Modules\Library\Models\Collection,id'],
            'acquisition' => ['required', Rule::in(Item::ASAL)],
            'acquired_at' => ['nullable', 'date'],
            'price' => ['nullable', 'numeric', 'min:0'],
            'condition' => ['required', Rule::in(Item::KONDISI)],
            'condition_note' => ['nullable', 'string', 'max:1000'],
            'room_id' => ['nullable', 'integer', 'exists:App\Modules\Library\Models\Room,id'],
            'shelf_no' => ['nullable', 'string', 'max:10'],
            'box_no' => ['nullable', 'string', 'max:10'],
        ], [], [
            'inventory_number' => 'nomor inventaris', 'collection_id' => 'koleksi',
            'acquisition' => 'asal', 'condition' => 'kondisi',
        ]);

        $koleksi = LibraryCollection::query()->findOrFail($data['collection_id']);

        if ($koleksi->isEbook()) {
            return back()->withInput()->with('galat', 'Ebook tidak punya eksemplar fisik.');
        }

        Item::query()->create($data);

        return back()->with('sukses', 'Eksemplar '.$data['inventory_number'].' terdaftar.');
    }

    public function updateItemCondition(Request $request, Item $eksemplar): RedirectResponse
    {
        $data = $request->validate([
            'condition' => ['required', Rule::in(Item::KONDISI)],
            'condition_note' => ['nullable', 'string', 'max:1000'],
        ], [], ['condition' => 'kondisi']);

        // Kondisi fisik saja — "sedang dipinjam" tidak pernah disimpan di
        // sini, ia dihitung dari peminjaman yang belum kembali.
        $eksemplar->update($data);

        return back()->with('sukses', 'Kondisi '.$eksemplar->inventory_number.' diperbarui.');
    }

    // --------------------------------------------------------- anggota

    public function members(): View
    {
        return view('library::anggota.index', [
            'anggota' => Member::query()->withCount([
                'loans as dipinjam_count' => fn ($q) => $q->whereNull('returned_at'),
            ])->orderBy('member_number')->limit(200)->get(),
            'jenis' => Member::JENIS,
        ]);
    }

    public function storeMember(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'member_number' => ['required', 'string', 'max:20', 'unique:App\Modules\Library\Models\Member,member_number'],
            'name' => ['required', 'string', 'max:150'],
            'member_type' => ['required', Rule::in(Member::JENIS)],
            'person_ref' => ['nullable', 'string', 'max:30'],
            'birth_date' => ['nullable', 'date'],
            'sex' => ['nullable', 'in:L,P'],
            'address' => ['nullable', 'string', 'max:200'],
            'phone' => ['nullable', 'string', 'max:30'],
            'email' => ['nullable', 'email', 'max:100'],
            'joined_at' => ['required', 'date'],
            'expires_at' => ['nullable', 'date', 'after_or_equal:joined_at'],
        ], [], [
            'member_number' => 'nomor anggota', 'name' => 'nama', 'member_type' => 'jenis anggota',
            'joined_at' => 'tanggal bergabung', 'expires_at' => 'masa berlaku',
        ]);

        Member::query()->create($data + ['is_active' => true]);

        return back()->with('sukses', 'Anggota '.$data['name'].' terdaftar.');
    }

    // -------------------------------------------------------- sirkulasi

    public function index(): View
    {
        return view('library::sirkulasi.index', [
            // Yang lewat tempo tampil TERPISAH dan di atas: daftar terbaru
            // justru menyembunyikan buku yang paling lama tidak kembali.
            'terlambat' => $this->sirkulasi->overdue(),
            'berjalan' => Loan::query()->with(['member', 'item.collection'])
                ->whereNull('returned_at')->orderBy('due_date')->limit(100)->get(),
            'riwayat' => Loan::query()->with(['member', 'item.collection'])
                ->whereNotNull('returned_at')->latest('returned_at')->limit(50)->get(),
            'denda' => Fine::query()->with(['member', 'loan'])->latest('charged_at')->limit(50)->get(),
            'anggota' => Member::query()->where('is_active', true)->orderBy('name')->get(),
            'tersedia' => Item::query()->with('collection')
                ->where('condition', Item::KONDISI_BAIK)
                ->whereDoesntHave('loans', fn ($q) => $q->whereNull('returned_at'))
                ->orderBy('inventory_number')->limit(200)->get(),
            'kondisi' => Item::KONDISI,
            'jenisDenda' => FineType::query()->where('is_active', true)->orderBy('name')->get(),
        ]);
    }

    public function borrow(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'member_id' => ['required', 'integer', 'exists:App\Modules\Library\Models\Member,id'],
            'item_id' => ['required', 'integer', 'exists:App\Modules\Library\Models\Item,id'],
        ], [], ['member_id' => 'anggota', 'item_id' => 'eksemplar']);

        try {
            $pinjam = $this->sirkulasi->borrow(
                Member::query()->findOrFail($data['member_id']),
                Item::query()->findOrFail($data['item_id']),
                $request->user()->id,
                $request->user()->name
            );
        } catch (LibraryException $e) {
            return back()->withInput()->with('galat', $e->getMessage());
        }

        return back()->with('sukses', 'Peminjaman '.$pinjam->loan_number.' tercatat, jatuh tempo '.$pinjam->due_date->format('d-m-Y').'.');
    }

    public function returnItem(Request $request, Loan $pinjaman): RedirectResponse
    {
        $data = $request->validate([
            'condition' => ['required', Rule::in(Item::KONDISI)],
            'note' => ['nullable', 'string', 'max:1000'],
        ], [], ['condition' => 'kondisi saat kembali']);

        try {
            $this->sirkulasi->returnItem(
                $pinjaman,
                $data['condition'],
                $request->user()->id,
                $request->user()->name,
                $data['note'] ?? null
            );
        } catch (LibraryException $e) {
            return back()->with('galat', $e->getMessage());
        }

        return back()->with('sukses', 'Pengembalian '.$pinjaman->loan_number.' tercatat.');
    }

    public function settleFine(Request $request, Fine $denda): RedirectResponse
    {
        $data = $request->validate([
            'tindakan' => ['required', 'in:bayar,bebaskan'],
            'paid_amount' => ['nullable', 'numeric', 'min:0'],
            'waived_reason' => ['nullable', 'string', 'max:1000'],
        ], [], ['tindakan' => 'tindakan', 'paid_amount' => 'jumlah bayar', 'waived_reason' => 'alasan pembebasan']);

        try {
            if ($data['tindakan'] === 'bebaskan') {
                $this->sirkulasi->waive($denda, (string) ($data['waived_reason'] ?? ''), $request->user()->id);
            } else {
                $this->sirkulasi->pay($denda, (float) ($data['paid_amount'] ?? 0), $request->user()->id);
            }
        } catch (LibraryException $e) {
            return back()->with('galat', $e->getMessage());
        }

        return back()->with('sukses', 'Denda '.$denda->fine_number.' diselesaikan.');
    }

    // ------------------------------------------------------ pengaturan

    public function settings(): View
    {
        return view('library::pengaturan.index', [
            'aktif' => LoanPolicy::query()->where('is_active', true)->first(),
            'riwayat' => LoanPolicy::query()->orderByDesc('effective_from')->limit(20)->get(),
            'jenisDenda' => FineType::query()->orderBy('code')->get(),
        ]);
    }

    public function storeSettings(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'max_items' => ['required', 'integer', 'min:1'],
            'loan_days' => ['required', 'integer', 'min:1'],
            'daily_fine' => ['required', 'numeric', 'min:0'],
        ], [], ['max_items' => 'batas eksemplar', 'loan_days' => 'lama pinjam', 'daily_fine' => 'denda harian']);

        $this->sirkulasi->setPolicy($data, $request->user()->id);

        return back()->with('sukses', 'Pengaturan peminjaman baru berlaku; yang lama disimpan sebagai riwayat.');
    }

    public function storeFineType(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'max:20', 'unique:App\Modules\Library\Models\FineType,code'],
            'name' => ['required', 'string', 'max:100'],
            'amount' => ['required', 'numeric', 'min:0'],
        ], [], ['code' => 'kode', 'name' => 'jenis denda', 'amount' => 'besaran']);

        FineType::query()->create($data + ['is_active' => true]);

        return back()->with('sukses', 'Jenis denda "'.$data['name'].'" ditambahkan.');
    }
}
