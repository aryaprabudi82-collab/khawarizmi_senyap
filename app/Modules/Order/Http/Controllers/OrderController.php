<?php

namespace App\Modules\Order\Http\Controllers;

use App\Modules\Order\Models\LabRadiologyOrder;
use App\Modules\Order\Models\OrderItem;
use App\Modules\Order\Models\TestCatalog;
use App\Modules\Order\Services\OrderException;
use App\Modules\Order\Services\OrderService;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Satu controller untuk lab dan radiologi karena siklusnya identik; yang
 * membedakan hanya kategori dari segmen URL.
 *
 * Permission-nya berbeda per kategori (periksa_lab vs periksa_radiologi),
 * dan itu tidak bisa digerbangi lewat middleware 'can:' yang statis karena
 * nilainya baru diketahui dari parameter rute saat runtime. Karena itu
 * setiap aksi memeriksa sendiri lewat assertAccess(), bukan lewat middleware
 * di berkas rute.
 */
class OrderController
{
    public function __construct(private readonly OrderService $orders) {}

    /** Antrean penunjang: satu layar untuk lab dan radiologi, disaring kategori. */
    public function index(Request $request, string $kategori): View
    {
        $this->assertAccess($request, $kategori);

        $tanggal = CarbonImmutable::parse($request->query('tanggal', now()->toDateString()))->startOfDay();
        $status = $request->query('status');

        $daftar = LabRadiologyOrder::query()
            ->where('category', $kategori)
            ->whereDate('requested_at', $tanggal->toDateString())
            ->when($status, fn ($q) => $q->where('status', $status))
            ->orderByRaw("CASE status
                WHEN 'diproses' THEN 1
                WHEN 'diminta' THEN 2
                WHEN 'hasil-tersedia' THEN 3
                ELSE 4 END")
            ->orderBy('requested_at')
            ->paginate(50)
            ->withQueryString();

        $ringkasan = LabRadiologyOrder::query()
            ->where('category', $kategori)
            ->whereDate('requested_at', $tanggal->toDateString())
            ->selectRaw('status, count(*) as jumlah')
            ->groupBy('status')
            ->pluck('jumlah', 'status');

        return view('order::orders.index', [
            'kategori' => $kategori,
            'daftar' => $daftar,
            'ringkasan' => $ringkasan,
            'tanggal' => $tanggal,
            'status' => $status,
        ]);
    }

    public function createForRegistration(Request $request, string $kategori, int $registrasi): RedirectResponse
    {
        $this->assertAccess($request, $kategori);

        $data = $request->validate([
            'test_ids' => ['nullable', 'array'],
            'test_ids.*' => ['integer'],
            'clinical_notes' => ['nullable', 'string', 'max:1000'],
        ], [], ['test_ids' => 'pemeriksaan']);

        try {
            $order = $this->orders->create(
                registrationId: $registrasi,
                category: $kategori,
                testIds: $data['test_ids'] ?? [],
                clinicalNotes: $data['clinical_notes'] ?? null,
                actor: $request->user(),
            );
        } catch (OrderException $e) {
            return back()->with('galat', $e->getMessage());
        }

        return redirect()->route('order.show', [$kategori, $order]);
    }

    public function show(Request $request, string $kategori, LabRadiologyOrder $order): View
    {
        $this->assertAccess($request, $kategori);

        return view('order::orders.show', [
            'kategori' => $kategori,
            'order' => $order->load('items.images'),
            'katalog' => TestCatalog::query()
                ->where('category', $kategori)
                ->where('is_active', true)
                ->orderBy('name')
                ->get(),
        ]);
    }

    public function storeItem(Request $request, string $kategori, LabRadiologyOrder $order): RedirectResponse
    {
        $this->assertAccess($request, $kategori);

        $data = $request->validate(['test_id' => ['required', 'integer']]);

        try {
            $this->orders->addItem($order, $data['test_id']);
        } catch (OrderException $e) {
            return back()->with('galat', $e->getMessage());
        }

        return back()->with('sukses', 'Pemeriksaan ditambahkan.');
    }

    public function destroyItem(Request $request, string $kategori, OrderItem $item): RedirectResponse
    {
        $this->assertAccess($request, $kategori);
        $order = $item->order;

        try {
            $this->orders->removeItem($item);
        } catch (OrderException $e) {
            return back()->with('galat', $e->getMessage());
        }

        return redirect()->route('order.show', [$kategori, $order])->with('sukses', 'Pemeriksaan dikeluarkan dari order.');
    }

    public function startProcessing(Request $request, string $kategori, LabRadiologyOrder $order): RedirectResponse
    {
        $this->assertAccess($request, $kategori);

        try {
            $this->orders->startProcessing($order);
        } catch (OrderException $e) {
            return back()->with('galat', $e->getMessage());
        }

        return back()->with('sukses', 'Order mulai diproses.');
    }

    public function storeResult(Request $request, string $kategori, OrderItem $item): RedirectResponse
    {
        $this->assertAccess($request, $kategori);

        $data = $request->validate([
            'result_numeric' => ['nullable', 'numeric'],
            'result_text' => ['nullable', 'string', 'max:200'],
            'result_notes' => ['nullable', 'string', 'max:5000'],
        ]);

        try {
            $this->orders->enterResult(
                item: $item,
                numeric: isset($data['result_numeric']) ? (float) $data['result_numeric'] : null,
                text: $data['result_text'] ?? null,
                notes: $data['result_notes'] ?? null,
                actor: $request->user(),
            );
        } catch (OrderException $e) {
            return back()->with('galat', $e->getMessage());
        }

        return back()->with('sukses', 'Hasil tersimpan.');
    }

    public function verify(Request $request, string $kategori, LabRadiologyOrder $order): RedirectResponse
    {
        $this->assertAccess($request, $kategori);

        try {
            $this->orders->verify($order, $request->user());
        } catch (OrderException $e) {
            return back()->with('galat', $e->getMessage());
        }

        return back()->with('sukses', 'Hasil diverifikasi dan order selesai.');
    }

    public function cancel(Request $request, string $kategori, LabRadiologyOrder $order): RedirectResponse
    {
        $this->assertAccess($request, $kategori);

        $data = $request->validate([
            'alasan' => ['required', 'string', 'min:5', 'max:255'],
        ], [], ['alasan' => 'alasan pembatalan']);

        try {
            $this->orders->cancel($order, $data['alasan']);
        } catch (OrderException $e) {
            return back()->with('galat', $e->getMessage());
        }

        return redirect()->route('order.index', $kategori)->with('sukses', 'Order dibatalkan.');
    }

    /** Pencarian katalog pemeriksaan untuk formulir permintaan. */
    public function searchCatalog(Request $request, string $kategori)
    {
        $this->assertAccess($request, $kategori);

        return TestCatalog::query()
            ->search((string) $request->query('q', ''), $kategori)
            ->orderBy('name')
            ->limit(20)
            ->get()
            ->map(fn (TestCatalog $t) => [
                'id' => $t->id,
                'label' => $t->code . ' — ' . $t->name,
                'price' => (float) $t->price,
            ]);
    }

    /**
     * Kategori harus salah satu nilai yang dikenal, dan pengguna harus punya
     * permission yang sesuai untuk kategori itu — periksa_lab untuk lab,
     * periksa_radiologi untuk radiologi, pemeriksaan_lab_pa untuk patologi
     * anatomi. Ketiga permission ini datang dari domain A Khanza (menu yang
     * sama dengan registrasi), diberikan lewat extra_permissions peran,
     * bukan lewat context-grant konteks order.
     */
    private function assertAccess(Request $request, string $kategori): void
    {
        $permission = match ($kategori) {
            LabRadiologyOrder::CATEGORY_LAB => 'periksa_lab',
            LabRadiologyOrder::CATEGORY_RADIOLOGI => 'periksa_radiologi',
            LabRadiologyOrder::CATEGORY_PA => 'pemeriksaan_lab_pa',
            default => null,
        };

        abort_unless($permission !== null, 404);

        abort_unless($request->user()?->can($permission) === true, 403);
    }
}
