<?php

namespace App\Modules\Retail\Http\Controllers;

use App\Modules\Retail\Models\Member;
use App\Modules\Retail\Models\PriceTier;
use App\Modules\Retail\Models\Product;
use App\Modules\Retail\Models\Sale;
use App\Modules\Retail\Services\RetailException;
use App\Modules\Retail\Services\RetailStockLedger;
use App\Modules\Retail\Services\SalesService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class SalesController
{
    public function __construct(
        private readonly SalesService $jual,
        private readonly RetailStockLedger $ledger,
    ) {}

    public function index(Request $request): View
    {
        $dari = $request->query('dari', now()->toDateString());
        $sampai = $request->query('sampai', now()->toDateString());

        return view('retail::penjualan.index', [
            'penjualan' => Sale::query()->with(['items.product', 'member', 'priceTier', 'payments'])
                ->latest('sold_at')->limit(50)->get(),
            'piutang' => $this->jual->outstandingReceivables(),

            /*
             * Empat kode rekap Khanza (pendapatan harian, penjualan harian,
             * piutang harian, keuntungan barang) dilayani SATU hitungan:
             * keempatnya membaca data yang sama dari sudut berbeda, dan empat
             * layar berarti empat tempat yang bisa berbeda jawabannya untuk
             * hari yang sama.
             */
            'rekap' => $this->jual->dailyRecap($dari, $sampai),
            'untungBarang' => $this->jual->profitByProduct($dari, $sampai),

            'produk' => Product::query()->where('is_active', true)->orderBy('name')->get(),
            'saldo' => $this->ledger->stockMap(),
            'member' => Member::query()->where('is_active', true)->orderBy('name')->get(),
            'tingkat' => PriceTier::query()->where('is_active', true)->orderBy('position')->get(),
            'periode' => ['dari' => $dari, 'sampai' => $sampai],
        ]);
    }

    public function storeMember(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'member_number' => ['required', 'string', 'max:20', 'unique:retail.members,member_number'],
            'name' => ['required', 'string', 'max:150'],
            'sex' => ['nullable', 'in:L,P'],
            'phone' => ['nullable', 'string', 'max:30'],
            'address' => ['nullable', 'string', 'max:200'],
            'default_price_tier_id' => ['nullable', 'integer', 'exists:retail.price_tiers,id'],
        ], [], ['member_number' => 'nomor member', 'name' => 'nama', 'default_price_tier_id' => 'tingkat harga']);

        Member::query()->create($data + ['joined_on' => now()->toDateString(), 'is_active' => true]);

        return back()->with('sukses', 'Member "'.$data['name'].'" terdaftar.');
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'payment_type' => ['required', 'in:tunai,piutang'],
            'member_id' => ['nullable', 'integer', 'exists:retail.members,id'],
            'price_tier_id' => ['nullable', 'integer', 'exists:retail.price_tiers,id'],
            'buyer_name' => ['nullable', 'string', 'max:150'],
            'discount' => ['nullable', 'numeric', 'min:0'],
            'down_payment' => ['nullable', 'numeric', 'min:0'],
            'due_on' => ['nullable', 'date'],
            'note' => ['nullable', 'string', 'max:500'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.product_id' => ['required', 'integer', 'exists:retail.products,id'],
            'items.*.quantity' => ['required', 'integer', 'min:1'],
        ], [], [
            'payment_type' => 'cara bayar', 'items' => 'barang',
            'due_on' => 'jatuh tempo', 'down_payment' => 'uang muka',
        ]);

        $member = isset($data['member_id']) ? Member::query()->find($data['member_id']) : null;
        $tingkat = isset($data['price_tier_id']) ? PriceTier::query()->find($data['price_tier_id']) : null;
        $items = array_values(array_filter(
            $data['items'],
            fn ($b) => filled($b['product_id'] ?? null) && (int) ($b['quantity'] ?? 0) > 0
        ));
        unset($data['items'], $data['member_id'], $data['price_tier_id']);

        try {
            $penjualan = $this->jual->sell(
                $data,
                $items,
                $member,
                $tingkat,
                $request->user()->id,
                $request->user()->name
            );
        } catch (RetailException $e) {
            return back()->withInput()->with('galat', $e->getMessage());
        }

        return back()->with('sukses', 'Penjualan '.$penjualan->sale_number.' tercatat.');
    }

    public function pay(Request $request, Sale $penjualan): RedirectResponse
    {
        $data = $request->validate([
            'amount' => ['required', 'numeric', 'min:0.01'],
            'method' => ['nullable', 'string', 'max:30'],
        ], [], ['amount' => 'jumlah bayar']);

        try {
            $this->jual->recordPayment(
                $penjualan,
                (float) $data['amount'],
                ['method' => $data['method'] ?? 'tunai'],
                $request->user()->id,
                $request->user()->name
            );
        } catch (RetailException $e) {
            return back()->with('galat', $e->getMessage());
        }

        return back()->with('sukses', 'Pembayaran piutang tercatat.');
    }

    public function storeReturn(Request $request, Sale $penjualan): RedirectResponse
    {
        $data = $request->validate([
            'reason' => ['required', 'string', 'max:1000'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.product_id' => ['required', 'integer', 'exists:retail.products,id'],
            'items.*.quantity' => ['required', 'integer', 'min:0'],
        ], [], ['reason' => 'alasan retur', 'items' => 'barang']);

        $items = array_values(array_filter(
            $data['items'],
            fn ($b) => filled($b['product_id'] ?? null) && (int) ($b['quantity'] ?? 0) > 0
        ));
        unset($data['items']);

        try {
            $retur = $this->jual->returnSale($penjualan, $data, $items, $request->user()->id, $request->user()->name);
        } catch (RetailException $e) {
            return back()->with('galat', $e->getMessage());
        }

        return back()->with('sukses', 'Retur '.$retur->return_number.' tercatat.');
    }
}
