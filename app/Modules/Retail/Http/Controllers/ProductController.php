<?php

namespace App\Modules\Retail\Http\Controllers;

use App\Modules\Retail\Models\Category;
use App\Modules\Retail\Models\PriceTier;
use App\Modules\Retail\Models\PricingPolicy;
use App\Modules\Retail\Models\Product;
use App\Modules\Retail\Models\StockMovement;
use App\Modules\Retail\Models\StockOpname;
use App\Modules\Retail\Models\StockOpnameItem;
use App\Modules\Retail\Models\Supplier;
use App\Modules\Retail\Services\ProductService;
use App\Modules\Retail\Services\RetailException;
use App\Modules\Retail\Services\RetailStockLedger;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class ProductController
{
    public function __construct(
        private readonly ProductService $produk,
        private readonly RetailStockLedger $ledger,
    ) {}

    public function index(): View
    {
        return view('retail::produk.index', [
            'produk' => Product::query()->with(['category', 'prices.tier'])->orderBy('name')->limit(300)->get(),
            'saldo' => $this->ledger->stockMap(),
            'kategori' => Category::query()->where('is_active', true)->orderBy('name')->get(),
            'suplier' => Supplier::query()->where('is_active', true)->orderBy('name')->get(),
            'tingkat' => PriceTier::query()->where('is_active', true)->orderBy('position')->get(),
            'patokan' => PricingPolicy::query()->with('tier')->where('is_active', true)->get(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'max:40', 'unique:retail.products,code'],
            'name' => ['required', 'string', 'max:150'],
            'category_id' => ['nullable', 'integer', 'exists:retail.categories,id'],
            'unit' => ['required', 'string', 'max:30'],
            'base_cost' => ['required', 'numeric', 'min:0'],
            'minimum_stock' => ['nullable', 'integer', 'min:0'],
        ], [], [
            'code' => 'kode barang', 'name' => 'nama barang', 'unit' => 'satuan',
            'base_cost' => 'harga pokok', 'minimum_stock' => 'stok minimum',
        ]);

        Product::query()->create($data + ['is_active' => true]);

        return back()->with('sukses', 'Barang "'.$data['name'].'" terdaftar.');
    }

    public function storeMaster(Request $request): RedirectResponse
    {
        $jenisMaster = [
            'kategori' => Category::class,
            'suplier' => Supplier::class,
            'tingkat-harga' => PriceTier::class,
        ];

        $data = $request->validate([
            'master' => ['required', Rule::in(array_keys($jenisMaster))],
            'code' => ['required', 'string', 'max:20'],
            'name' => ['required', 'string', 'max:150'],
        ], [], ['master' => 'jenis master', 'code' => 'kode', 'name' => 'nama']);

        /** @var class-string<Model> $model */
        $model = $jenisMaster[$data['master']];

        if ($model::query()->where('code', $data['code'])->exists()) {
            return back()->withInput()->with('galat', 'Kode "'.$data['code'].'" sudah dipakai pada master itu.');
        }

        $model::query()->create([
            'code' => $data['code'],
            'name' => $data['name'],
            'is_active' => true,
        ]);

        return back()->with('sukses', 'Data master "'.$data['name'].'" ditambahkan.');
    }

    public function setPrice(Request $request, Product $barang): RedirectResponse
    {
        $data = $request->validate([
            'price_tier_id' => ['required', 'integer', 'exists:retail.price_tiers,id'],
            'price' => ['required', 'numeric', 'min:0'],
        ], [], ['price_tier_id' => 'tingkat harga', 'price' => 'harga jual']);

        try {
            $this->produk->setPrice(
                $barang,
                PriceTier::query()->findOrFail($data['price_tier_id']),
                (float) $data['price']
            );
        } catch (RetailException $e) {
            return back()->with('galat', $e->getMessage());
        }

        return back()->with('sukses', 'Harga jual '.$barang->name.' diperbarui.');
    }

    public function setPricingPolicy(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'price_tier_id' => ['required', 'integer', 'exists:retail.price_tiers,id'],
            'markup_percent' => ['required', 'numeric', 'min:0', 'max:9999'],
        ], [], ['price_tier_id' => 'tingkat harga', 'markup_percent' => 'marjin']);

        $this->produk->setPricingPolicy(
            PriceTier::query()->findOrFail($data['price_tier_id']),
            (float) $data['markup_percent'],
            $request->user()->id
        );

        return back()->with('sukses', 'Patokan marjin baru berlaku; yang lama disimpan sebagai riwayat.');
    }

    // -------------------------------------------------- stok & opname

    public function stock(Request $request): View
    {
        $produkId = $request->query('barang');

        return view('retail::stok.index', [
            'produk' => Product::query()->orderBy('name')->get(),
            'saldo' => $this->ledger->stockMap(),

            /*
             * Riwayat barang & sirkulasi (tiga kode Khanza: toko_riwayat_barang,
             * toko_sirkulasi, toko_sirkulasi2) dilayani SATU buku besar yang
             * bisa disaring — ketiganya membaca data yang sama dengan penyaring
             * berbeda, dan tiga layar berarti tiga tempat memperbaiki satu
             * kesalahan yang sama.
             */
            'pergerakan' => StockMovement::query()
                ->with('product')
                ->when($produkId !== null, fn ($q) => $q->where('product_id', (int) $produkId))
                ->latest('occurred_at')->limit(200)->get(),
            'jenisPergerakan' => StockMovement::JENIS,
            'opname' => StockOpname::query()->with('items')->latest('counted_on')->limit(20)->get(),
            'filterBarang' => $produkId,
        ]);
    }

    public function openOpname(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'note' => ['nullable', 'string', 'max:1000'],
        ], [], ['note' => 'catatan']);

        $opname = $this->produk->openOpname($data, $request->user()->id, $request->user()->name);

        return back()->with('sukses', 'Sesi opname '.$opname->opname_number.' dibuka untuk '.$opname->items->count().' barang.');
    }

    public function recordCount(Request $request, StockOpnameItem $baris): RedirectResponse
    {
        $data = $request->validate([
            'counted_quantity' => ['nullable', 'integer', 'min:0'],
            'note' => ['nullable', 'string', 'max:500'],
        ], [], ['counted_quantity' => 'hasil hitung fisik']);

        try {
            $this->produk->recordCount(
                $baris,
                $data['counted_quantity'] ?? null,
                $data['note'] ?? null
            );
        } catch (RetailException $e) {
            return back()->with('galat', $e->getMessage());
        }

        return back()->with('sukses', 'Hasil hitung tersimpan.');
    }

    public function completeOpname(Request $request, StockOpname $opname): RedirectResponse
    {
        try {
            $this->produk->completeOpname($opname, $request->user()->id);
        } catch (RetailException $e) {
            return back()->with('galat', $e->getMessage());
        }

        return back()->with('sukses', 'Opname '.$opname->opname_number.' ditutup dan koreksinya masuk buku besar.');
    }
}
