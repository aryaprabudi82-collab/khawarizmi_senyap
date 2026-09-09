<?php

namespace App\Modules\Retail\Services;

use App\Modules\Retail\Models\GoodsReceipt;
use App\Modules\Retail\Models\Product;
use App\Modules\Retail\Models\PurchaseOrder;
use App\Modules\Retail\Models\Requisition;
use App\Modules\Retail\Models\StockMovement;
use App\Modules\Retail\Models\Supplier;
use App\Modules\Retail\Models\SupplierPayment;
use App\Modules\Retail\Models\SupplierReturn;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Rantai pengadaan toko (domain S item B).
 *
 * pengajuan -> pesanan -> penerimaan (+ hutang & pembayaran) -> retur.
 */
class ProcurementService
{
    public function __construct(
        private readonly RetailNumberAllocator $numbers,
        private readonly RetailStockLedger $ledger,
    ) {}

    // ---------------------------------------------------- pengajuan

    /** @param  array<int, array{product_id: int, quantity: int, note?: string|null}>  $items */
    public function requisition(array $data, array $items, ?int $createdBy = null): Requisition
    {
        if ($items === []) {
            throw new RetailException('Pengajuan tanpa barang tidak menyatakan apa pun.');
        }

        return DB::transaction(function () use ($data, $items, $createdBy) {
            $pengajuan = Requisition::query()->create($data + [
                'requisition_number' => $this->numbers->allocate('PGJ'),
                'requested_on' => now()->toDateString(),
                'status' => Requisition::STATUS_DIAJUKAN,
                'created_by' => $createdBy,
            ]);

            foreach ($items as $baris) {
                $pengajuan->items()->create([
                    'product_id' => $baris['product_id'],
                    'quantity' => $baris['quantity'],
                    'note' => $baris['note'] ?? null,
                ]);
            }

            return $pengajuan->load('items');
        });
    }

    /**
     * Menyetujui atau menolak pengajuan.
     *
     * PENOLAKAN WAJIB BERALASAN, PERSETUJUAN TIDAK — pengajuan yang
     * disetujui berbukti pada pesanan yang lahir sesudahnya; yang ditolak
     * tidak meninggalkan apa pun selain catatan ini.
     */
    public function decideRequisition(
        Requisition $requisition,
        bool $approved,
        ?string $note,
        string $decidedByName
    ): Requisition {
        if ($requisition->status !== Requisition::STATUS_DIAJUKAN) {
            throw new RetailException('Pengajuan ini sudah diputuskan.');
        }

        if (! $approved && blank($note)) {
            throw new RetailException('Penolakan pengajuan harus menyebutkan alasannya.');
        }

        $requisition->update([
            'status' => $approved ? Requisition::STATUS_DISETUJUI : Requisition::STATUS_DITOLAK,
            'decision_note' => $note,
            'decided_by_name' => $decidedByName,
            'decided_at' => now(),
        ]);

        return $requisition->refresh();
    }

    // ------------------------------------------------------ pesanan

    /** @param  array<int, array{product_id: int, quantity: int, unit_cost: float}>  $items */
    public function order(Supplier $supplier, array $data, array $items, ?Requisition $from = null, ?int $createdBy = null): PurchaseOrder
    {
        if ($items === []) {
            throw new RetailException('Pesanan tanpa barang tidak bisa dikirim ke suplier.');
        }

        if ($from !== null && $from->status !== Requisition::STATUS_DISETUJUI) {
            throw new RetailException(
                'Pengajuan yang belum disetujui tidak bisa jadi dasar pesanan — kalau bisa, '.
                'persetujuannya cuma formalitas yang dilewati saat sedang buru-buru.'
            );
        }

        return DB::transaction(function () use ($supplier, $data, $items, $from, $createdBy) {
            $pesanan = PurchaseOrder::query()->create($data + [
                'order_number' => $this->numbers->allocate('PO'),
                'supplier_id' => $supplier->id,
                'requisition_id' => $from?->id,
                'ordered_on' => now()->toDateString(),
                'status' => PurchaseOrder::STATUS_DRAFT,
                'created_by' => $createdBy,
            ]);

            foreach ($items as $baris) {
                $pesanan->items()->create([
                    'product_id' => $baris['product_id'],
                    'quantity' => $baris['quantity'],
                    'unit_cost' => $baris['unit_cost'],
                ]);
            }

            $from?->update(['status' => Requisition::STATUS_DIPROSES]);

            return $pesanan->load('items');
        });
    }

    public function sendOrder(PurchaseOrder $order): PurchaseOrder
    {
        if ($order->status !== PurchaseOrder::STATUS_DRAFT) {
            throw new RetailException('Pesanan ini sudah dikirim atau dibatalkan.');
        }

        $order->update(['status' => PurchaseOrder::STATUS_DIKIRIM]);

        return $order->refresh();
    }

    // --------------------------------------------------- penerimaan

    /**
     * Mencatat penerimaan barang.
     *
     * TIDAK BOLEH MELEBIHI SISA PESANAN. Barang yang datang lebih banyak
     * daripada yang dipesan bukan kelebihan yang menyenangkan: ia berarti
     * pesanannya salah dicatat, atau ada kiriman yang tidak pernah dipesan
     * siapa pun. Keduanya harus berhenti di meja penerimaan, bukan masuk
     * diam-diam ke stok lalu ditagihkan.
     *
     * @param  array<int, array{product_id: int, quantity: int}>  $items
     */
    public function receive(PurchaseOrder $order, array $data, array $items, ?int $receivedBy = null, ?string $receivedByName = null): GoodsReceipt
    {
        if (! in_array($order->status, [PurchaseOrder::STATUS_DIKIRIM, PurchaseOrder::STATUS_SEBAGIAN], true)) {
            throw new RetailException('Hanya pesanan yang sudah dikirim yang bisa diterima barangnya.');
        }

        if ($items === []) {
            throw new RetailException('Penerimaan tanpa barang tidak menambah apa pun.');
        }

        $sisa = $order->sisaPerProduk();

        return DB::transaction(function () use ($order, $data, $items, $sisa, $receivedBy, $receivedByName) {
            $penerimaan = GoodsReceipt::query()->create($data + [
                'receipt_number' => $this->numbers->allocate('TRM'),
                'order_id' => $order->id,
                'received_on' => now()->toDateString(),
                'payment_status' => GoodsReceipt::BAYAR_BELUM,
                'received_by' => $receivedBy,
                'received_by_name' => $receivedByName,
                'total_amount' => 0,
            ]);

            $total = 0.0;

            foreach ($items as $baris) {
                $produk = Product::query()->findOrFail($baris['product_id']);
                $jumlah = (int) $baris['quantity'];
                $tersisa = $sisa[$produk->id] ?? 0;

                if ($jumlah > $tersisa) {
                    throw new RetailException(
                        'Penerimaan '.$produk->name.' sebanyak '.$jumlah.' melebihi sisa pesanan ('.$tersisa.'). '.
                        'Kelebihan kiriman berarti pesanannya salah dicatat atau ada barang yang tidak pernah '.
                        'dipesan siapa pun — keduanya harus berhenti di meja penerimaan.'
                    );
                }

                $hargaSatuan = (float) $order->items->firstWhere('product_id', $produk->id)->unit_cost;

                $penerimaan->items()->create([
                    'product_id' => $produk->id,
                    'quantity' => $jumlah,
                    'unit_cost' => $hargaSatuan,
                ]);

                $this->ledger->record(
                    $produk,
                    StockMovement::MASUK,
                    $jumlah,
                    $hargaSatuan,
                    $penerimaan->receipt_number,
                    $receivedBy
                );

                /*
                 * Harga pokok barang mengikuti penerimaan TERAKHIR — dipakai
                 * mengusulkan harga jual berikutnya. HPP yang dipakai
                 * menghitung keuntungan dibekukan per baris penjualan;
                 * menyatukan keduanya membuat keuntungan bulan lalu berubah
                 * setiap kali ada penerimaan baru.
                 */
                $produk->update(['base_cost' => $hargaSatuan]);

                $total += $jumlah * $hargaSatuan;
            }

            $penerimaan->update(['total_amount' => round($total, 2)]);
            $this->refreshOrderStatus($order);

            return $penerimaan->refresh()->load('items');
        });
    }

    private function refreshOrderStatus(PurchaseOrder $order): void
    {
        // load() dipaksa, bukan loadMissing(): relasi yang sudah termuat
        // sebelum penerimaan ditulis akan menyimpan jumlah lama, dan
        // statusnya jadi salah. Bug yang sama pernah tertangkap pada
        // domain D item 2.
        $order->load('receipts.items', 'items');

        $sisa = array_filter($order->sisaPerProduk(), fn (int $n) => $n > 0);

        $order->update([
            'status' => $sisa === []
                ? PurchaseOrder::STATUS_DITERIMA
                : PurchaseOrder::STATUS_SEBAGIAN,
        ]);
    }

    // ---------------------------------------------- hutang & bayar

    /**
     * Mencatat pembayaran ke suplier.
     *
     * Hutang DIHITUNG dari selisih nilai penerimaan dan yang sudah
     * dibayar — saldo hutang yang disimpan sebagai kolom akan melenceng
     * begitu satu pembayaran gagal di tengah, dan yang tertinggal cuma
     * angka yang tidak bisa ditelusuri ke nota mana pun.
     */
    public function payReceipt(GoodsReceipt $receipt, float $amount, array $data = [], ?int $recordedBy = null, ?string $recordedByName = null): SupplierPayment
    {
        if ($amount <= 0) {
            throw new RetailException('Jumlah pembayaran harus lebih dari nol.');
        }

        $sisa = $receipt->sisaHutang();

        if ($amount > $sisa + 0.001) {
            throw new RetailException(
                'Pembayaran '.number_format($amount, 2).' melebihi sisa hutang '.number_format($sisa, 2).
                '. Kelebihan bayar yang dibiarkan berarti nota lain ikut terbayar di sini tanpa tercatat, '.
                'dan hutang atas nota itu akan tampak masih terbuka.'
            );
        }

        return DB::transaction(function () use ($receipt, $amount, $data, $recordedBy, $recordedByName) {
            $bayar = SupplierPayment::query()->create($data + [
                'payment_number' => $this->numbers->allocate('BYR'),
                'receipt_id' => $receipt->id,
                'paid_on' => now()->toDateString(),
                'amount' => $amount,
                'recorded_by' => $recordedBy,
                'recorded_by_name' => $recordedByName,
            ]);

            $terbayar = round((float) $receipt->payments()->sum('amount'), 2);

            $receipt->update([
                'paid_amount' => $terbayar,
                'payment_status' => match (true) {
                    $terbayar <= 0 => GoodsReceipt::BAYAR_BELUM,
                    $terbayar >= (float) $receipt->total_amount - 0.001 => GoodsReceipt::BAYAR_LUNAS,
                    default => GoodsReceipt::BAYAR_SEBAGIAN,
                },
            ]);

            return $bayar;
        });
    }

    /**
     * Hutang yang belum lunas, jatuh tempo terdekat di atas.
     *
     * @return Collection<int, GoodsReceipt>
     */
    public function outstandingPayables(): Collection
    {
        return GoodsReceipt::query()
            ->with('order.supplier')
            ->where('payment_status', '!=', GoodsReceipt::BAYAR_LUNAS)
            ->orderByRaw('due_on NULLS LAST')
            ->get();
    }

    // -------------------------------------------------------- retur

    /** @param  array<int, array{product_id: int, quantity: int, unit_cost?: float|null}>  $items */
    public function returnToSupplier(Supplier $supplier, array $data, array $items, ?GoodsReceipt $from = null, ?int $createdBy = null): SupplierReturn
    {
        if (blank($data['reason'] ?? null)) {
            throw new RetailException(
                'Retur ke suplier wajib beralasan — tanpa alasan, ia tidak bisa dibedakan dari '.
                'barang yang hilang lalu dicatat sebagai retur.'
            );
        }

        if ($items === []) {
            throw new RetailException('Retur tanpa barang tidak mengurangi apa pun.');
        }

        return DB::transaction(function () use ($supplier, $data, $items, $from, $createdBy) {
            $retur = SupplierReturn::query()->create($data + [
                'return_number' => $this->numbers->allocate('RTB'),
                'supplier_id' => $supplier->id,
                'receipt_id' => $from?->id,
                'returned_on' => now()->toDateString(),
                'total_amount' => 0,
                'created_by' => $createdBy,
            ]);

            $total = 0.0;

            foreach ($items as $baris) {
                $produk = Product::query()->findOrFail($baris['product_id']);
                $jumlah = (int) $baris['quantity'];
                $harga = (float) ($baris['unit_cost'] ?? $produk->base_cost);

                $retur->items()->create([
                    'product_id' => $produk->id,
                    'quantity' => $jumlah,
                    'unit_cost' => $harga,
                ]);

                // Stok berkurang lewat buku besar, bukan lewat penimpaan
                // saldo — dan penjaga stok minus di ledger berlaku di sini.
                $this->ledger->record(
                    $produk,
                    StockMovement::RETUR_KELUAR,
                    $jumlah,
                    $harga,
                    $retur->return_number,
                    $createdBy,
                    $data['reason']
                );

                $total += $jumlah * $harga;
            }

            $retur->update(['total_amount' => round($total, 2)]);

            return $retur->refresh()->load('items');
        });
    }
}
