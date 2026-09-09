<?php

namespace App\Modules\Retail\Services;

use App\Modules\Retail\Models\Member;
use App\Modules\Retail\Models\PriceTier;
use App\Modules\Retail\Models\Product;
use App\Modules\Retail\Models\Sale;
use App\Modules\Retail\Models\SalePayment;
use App\Modules\Retail\Models\SaleReturn;
use App\Modules\Retail\Models\StockMovement;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Penjualan, piutang & rekap toko (domain S item C).
 */
class SalesService
{
    public function __construct(
        private readonly RetailNumberAllocator $numbers,
        private readonly RetailStockLedger $ledger,
    ) {}

    /**
     * Mencatat penjualan — tunai maupun piutang, SATU mekanisme.
     *
     * Khanza memisahkannya jadi dua tabel; laporan penjualan yang lupa
     * salah satunya menjawab dengan tenang dengan angka yang lebih kecil
     * daripada kenyataannya.
     *
     * @param  array<int, array{product_id: int, quantity: int, discount?: float}>  $items
     */
    public function sell(
        array $data,
        array $items,
        ?Member $member = null,
        ?PriceTier $tier = null,
        ?int $cashierId = null,
        ?string $cashierName = null
    ): Sale {
        if ($items === []) {
            throw new RetailException('Penjualan tanpa barang tidak menjual apa pun.');
        }

        $caraBayar = $data['payment_type'] ?? Sale::TUNAI;

        if (! in_array($caraBayar, Sale::CARA_BAYAR, true)) {
            throw new RetailException('Cara bayar "'.$caraBayar.'" tidak dikenal.');
        }

        $tingkat = $tier ?? ($member?->defaultPriceTier);

        if ($tingkat === null) {
            throw new RetailException(
                'Tingkat harga harus ditentukan — tanpa itu, harga jual tiap barang tidak punya dasar.'
            );
        }

        return DB::transaction(function () use ($data, $items, $member, $tingkat, $caraBayar, $cashierId, $cashierName) {
            $penjualan = Sale::query()->create([
                'sale_number' => $this->numbers->allocate('JUAL'),
                'sold_at' => now(),
                'member_id' => $member?->id,
                'buyer_name' => $data['buyer_name'] ?? $member?->name,
                'price_tier_id' => $tingkat->id,
                'payment_type' => $caraBayar,
                'discount' => (float) ($data['discount'] ?? 0),
                'down_payment' => $caraBayar === Sale::PIUTANG ? (float) ($data['down_payment'] ?? 0) : 0,
                'due_on' => $caraBayar === Sale::PIUTANG ? ($data['due_on'] ?? null) : null,
                'payment_status' => $caraBayar === Sale::TUNAI ? Sale::LUNAS : Sale::BELUM,
                'cashier_id' => $cashierId,
                'cashier_name' => $cashierName,
                'note' => $data['note'] ?? null,
                'subtotal' => 0,
                'total' => 0,
            ]);

            $subtotal = 0.0;

            foreach ($items as $baris) {
                $produk = Product::query()->findOrFail($baris['product_id']);
                $jumlah = (int) $baris['quantity'];

                $harga = $produk->prices()->where('price_tier_id', $tingkat->id)->value('price');

                if ($harga === null) {
                    throw new RetailException(
                        'Harga '.$produk->name.' pada tingkat '.$tingkat->name.' belum ditetapkan. '.
                        'Menjual tanpa harga yang ditetapkan berarti kasir yang menentukannya di tempat.'
                    );
                }

                $potongan = (float) ($baris['discount'] ?? 0);
                $nilai = round($jumlah * (float) $harga - $potongan, 2);

                $penjualan->items()->create([
                    'product_id' => $produk->id,
                    'quantity' => $jumlah,
                    'unit_price' => $harga,
                    // Harga pokok DIBEKUKAN di sini.
                    'unit_cost' => $produk->base_cost,
                    'discount' => $potongan,
                    'subtotal' => $nilai,
                ]);

                $this->ledger->record(
                    $produk,
                    StockMovement::KELUAR,
                    $jumlah,
                    (float) $produk->base_cost,
                    $penjualan->sale_number,
                    $cashierId
                );

                $subtotal += $nilai;
            }

            $total = round($subtotal - (float) $penjualan->discount, 2);

            $penjualan->update(['subtotal' => round($subtotal, 2), 'total' => $total]);

            if ($caraBayar === Sale::PIUTANG && (float) $penjualan->down_payment > 0) {
                $this->recordPayment($penjualan->refresh(), (float) $penjualan->down_payment, [
                    'note' => 'Uang muka',
                ], $cashierId, $cashierName);
            } else {
                $this->refreshPaymentStatus($penjualan->refresh());
            }

            return $penjualan->refresh()->load('items');
        });
    }

    /**
     * Mencatat pembayaran piutang.
     *
     * Sisa piutang DIHITUNG dari selisih total dan seluruh pembayaran —
     * `tokopiutang.sisapiutang` Khanza adalah saldo yang menempel pada
     * notanya, dan saldo tanpa buku pembayaran akan melenceng begitu satu
     * cicilan gagal di tengah.
     */
    public function recordPayment(Sale $sale, float $amount, array $data = [], ?int $recordedBy = null, ?string $recordedByName = null): SalePayment
    {
        if ($sale->payment_type !== Sale::PIUTANG) {
            throw new RetailException('Penjualan tunai tidak punya piutang untuk dibayar.');
        }

        if ($amount <= 0) {
            throw new RetailException('Jumlah pembayaran harus lebih dari nol.');
        }

        $sisa = $sale->sisaPiutang();

        if ($amount > $sisa + 0.001) {
            throw new RetailException(
                'Pembayaran '.number_format($amount, 2).' melebihi sisa piutang '.number_format($sisa, 2).'.'
            );
        }

        return DB::transaction(function () use ($sale, $amount, $data, $recordedBy, $recordedByName) {
            $bayar = SalePayment::query()->create($data + [
                'payment_number' => $this->numbers->allocate('BYP'),
                'sale_id' => $sale->id,
                'paid_at' => now(),
                'amount' => $amount,
                'recorded_by' => $recordedBy,
                'recorded_by_name' => $recordedByName,
            ]);

            $this->refreshPaymentStatus($sale->refresh());

            return $bayar;
        });
    }

    /**
     * Retur penjualan.
     *
     * TIDAK BOLEH MELEBIHI YANG DIJUAL. Retur lebih banyak daripada yang
     * pernah dibeli berarti barang dari tempat lain masuk ke stok sambil
     * uangnya keluar dari kas.
     *
     * Retur atas penjualan PIUTANG mengurangi tagihan, bukan mengeluarkan
     * kas: menyamakannya dengan retur tunai membuat kas tercatat keluar
     * untuk uang yang belum pernah masuk.
     *
     * @param  array<int, array{product_id: int, quantity: int}>  $items
     */
    public function returnSale(Sale $sale, array $data, array $items, ?int $recordedBy = null, ?string $recordedByName = null): SaleReturn
    {
        if (blank($data['reason'] ?? null)) {
            throw new RetailException('Retur penjualan wajib beralasan.');
        }

        if ($items === []) {
            throw new RetailException('Retur tanpa barang tidak mengembalikan apa pun.');
        }

        $sale->load(['items', 'returns.items']);
        $sisa = $sale->sisaBisaDiretur();

        return DB::transaction(function () use ($sale, $data, $items, $sisa, $recordedBy, $recordedByName) {
            $retur = SaleReturn::query()->create([
                'return_number' => $this->numbers->allocate('RTJ'),
                'sale_id' => $sale->id,
                'returned_at' => now(),
                'reason' => $data['reason'],
                // Penjualan piutang: tagihannya yang berkurang, bukan kasnya.
                'refunded_in_cash' => $sale->payment_type === Sale::TUNAI,
                'total_amount' => 0,
                'recorded_by' => $recordedBy,
                'recorded_by_name' => $recordedByName,
            ]);

            $total = 0.0;

            foreach ($items as $baris) {
                $produk = Product::query()->findOrFail($baris['product_id']);
                $jumlah = (int) $baris['quantity'];
                $tersisa = $sisa[$produk->id] ?? 0;

                if ($jumlah > $tersisa) {
                    throw new RetailException(
                        'Retur '.$produk->name.' sebanyak '.$jumlah.' melebihi yang bisa diretur ('.$tersisa.'). '.
                        'Retur lebih banyak daripada yang pernah dibeli berarti barang dari tempat lain masuk '.
                        'ke stok sambil uangnya keluar dari kas.'
                    );
                }

                $asal = $sale->items->firstWhere('product_id', $produk->id);

                $retur->items()->create([
                    'product_id' => $produk->id,
                    'quantity' => $jumlah,
                    'unit_price' => $asal->unit_price,
                    'unit_cost' => $asal->unit_cost,
                ]);

                $this->ledger->record(
                    $produk,
                    StockMovement::RETUR_MASUK,
                    $jumlah,
                    (float) $asal->unit_cost,
                    $retur->return_number,
                    $recordedBy,
                    $data['reason']
                );

                $total += $jumlah * (float) $asal->unit_price;
            }

            $retur->update(['total_amount' => round($total, 2)]);

            if ($sale->payment_type === Sale::PIUTANG) {
                $this->refreshPaymentStatus($sale->refresh());
            }

            return $retur->refresh()->load('items');
        });
    }

    private function refreshPaymentStatus(Sale $sale): void
    {
        if ($sale->payment_type === Sale::TUNAI) {
            $sale->update(['payment_status' => Sale::LUNAS]);

            return;
        }

        $sisa = $sale->sisaPiutang();
        $dibayar = (float) $sale->payments()->sum('amount');

        $sale->update([
            'payment_status' => match (true) {
                $sisa <= 0.001 => Sale::LUNAS,
                $dibayar > 0 => Sale::SEBAGIAN,
                default => Sale::BELUM,
            },
        ]);
    }

    // ------------------------------------------------------- rekap

    /**
     * Rekap harian: pendapatan (kas masuk), penjualan (nilai transaksi),
     * piutang, dan keuntungan.
     *
     * EMPAT KODE REKAP KHANZA DILAYANI SATU HITUNGAN. Keempatnya membaca
     * data yang sama dari sudut berbeda, dan empat layar berarti empat
     * tempat yang bisa berbeda jawabannya untuk hari yang sama.
     *
     * @return array<string, float|int>
     */
    public function dailyRecap(string $from, string $until): array
    {
        $penjualan = Sale::query()->whereBetween('sold_at', [$from.' 00:00:00', $until.' 23:59:59']);

        $nilaiJual = (float) (clone $penjualan)->sum('total');
        $jumlahNota = (clone $penjualan)->count();

        // Kas masuk: tunai penuh + seluruh cicilan piutang pada rentang itu.
        $tunai = (float) (clone $penjualan)->where('payment_type', Sale::TUNAI)->sum('total');
        $cicilan = (float) SalePayment::query()
            ->whereBetween('paid_at', [$from.' 00:00:00', $until.' 23:59:59'])
            ->sum('amount');

        $modal = (float) DB::table('retail.sale_items as si')
            ->join('retail.sales as s', 's.id', '=', 'si.sale_id')
            ->whereBetween('s.sold_at', [$from.' 00:00:00', $until.' 23:59:59'])
            ->selectRaw('COALESCE(SUM(si.quantity * si.unit_cost), 0) AS modal')
            ->value('modal');

        $returNilai = (float) DB::table('retail.sale_returns')
            ->whereBetween('returned_at', [$from.' 00:00:00', $until.' 23:59:59'])
            ->sum('total_amount');

        return [
            'jumlah_nota' => $jumlahNota,
            'nilai_penjualan' => round($nilaiJual, 2),
            'pendapatan_kas' => round($tunai + $cicilan, 2),
            'nilai_retur' => round($returNilai, 2),
            'modal_terjual' => round($modal, 2),
            // Keuntungan dari harga pokok yang DIBEKUKAN per baris, bukan
            // dari harga pokok barang saat laporan dibuat.
            'keuntungan' => round($nilaiJual - $modal - $returNilai, 2),
        ];
    }

    /**
     * Keuntungan per barang pada satu rentang.
     *
     * @return Collection<int, object>
     */
    public function profitByProduct(string $from, string $until): Collection
    {
        return collect(DB::select(
            'SELECT p.id, p.code, p.name,
                    SUM(si.quantity)                         AS terjual,
                    SUM(si.subtotal)                         AS omzet,
                    SUM(si.quantity * si.unit_cost)          AS modal,
                    SUM(si.subtotal - si.quantity * si.unit_cost) AS untung
               FROM retail.sale_items si
               JOIN retail.sales s   ON s.id = si.sale_id
               JOIN retail.products p ON p.id = si.product_id
              WHERE s.sold_at BETWEEN ? AND ?
              GROUP BY p.id, p.code, p.name
              ORDER BY untung DESC',
            [$from.' 00:00:00', $until.' 23:59:59']
        ));
    }

    /**
     * Piutang yang belum lunas, jatuh tempo terdekat di atas.
     *
     * @return Collection<int, Sale>
     */
    public function outstandingReceivables(): Collection
    {
        return Sale::query()
            ->with(['member', 'payments'])
            ->where('payment_type', Sale::PIUTANG)
            ->where('payment_status', '!=', Sale::LUNAS)
            ->orderByRaw('due_on NULLS LAST')
            ->get();
    }
}
