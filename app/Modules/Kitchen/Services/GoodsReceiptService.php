<?php

namespace App\Modules\Kitchen\Services;

use App\Modules\Kitchen\Models\GoodsReceipt;
use App\Modules\Kitchen\Models\PurchaseOrder;
use App\Modules\Platform\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * dapur_pemesanan — terima barang dari suplier dapur. Lewat
 * StockLedger::receive() (source='pembelian') yang sudah ada, bukan
 * ditulis manual. Tidak ada konsep pembayaran gabungan di sini (beda
 * dari farmasi/bayar_pemesanan_obat) — dikonfirmasi lewat xlsx,
 * dapur_pemesanan cuma satu baris paket "dapur", tidak dipakai ulang
 * di domain K/keuangan seperti pasangan farmasinya (pola sama dengan
 * penerimaan_non_medis domain E item B).
 */
class GoodsReceiptService
{
    public function __construct(
        private readonly NumberAllocator $numbers,
        private readonly StockLedger $ledger,
        private readonly PurchaseOrderService $orders,
    ) {}

    /** @param array<int, array{purchase_order_item_id: int, item_id: int, quantity: float}> $items */
    public function receive(PurchaseOrder $po, array $items, User $actor): GoodsReceipt
    {
        if (! in_array($po->status, [PurchaseOrder::STATUS_DIPESAN, PurchaseOrder::STATUS_DITERIMA_SEBAGIAN], true)) {
            throw new KitchenException('PO harus berstatus dipesan atau diterima sebagian sebelum barangnya diterima.');
        }

        if ($items === []) {
            throw new KitchenException('Penerimaan harus berisi minimal satu barang.');
        }

        return DB::transaction(function () use ($po, $items, $actor): GoodsReceipt {
            $penerimaan = GoodsReceipt::query()->create([
                'receipt_number' => $this->numbers->allocate('TRM'),
                'purchase_order_id' => $po->id,
                'received_at' => now(),
                'received_by' => $actor->id,
                'status' => GoodsReceipt::STATUS_DITERIMA,
            ]);

            foreach ($items as $baris) {
                $quantity = (float) $baris['quantity'];

                if ($quantity <= 0) {
                    continue;
                }

                $penerimaan->items()->create([
                    'purchase_order_item_id' => $baris['purchase_order_item_id'] ?? null,
                    'item_id' => $baris['item_id'],
                    'quantity_received' => $quantity,
                ]);

                $this->ledger->receive(
                    itemId: $baris['item_id'],
                    quantity: $quantity,
                    source: 'pembelian',
                    referenceType: 'goods-receipt',
                    referenceId: $penerimaan->id,
                    actor: $actor,
                    note: "Penerimaan {$penerimaan->receipt_number} / PO {$po->po_number}",
                );

                if (! empty($baris['purchase_order_item_id'])) {
                    DB::table('kitchen.purchase_order_items')
                        ->where('id', $baris['purchase_order_item_id'])
                        ->increment('quantity_received', $quantity);
                }
            }

            $this->orders->refreshReceivingStatus($po);

            return $penerimaan->fresh('items');
        });
    }

    /** verifikasi_penerimaan_dapur — QC pasca-terima, tidak mengubah stok. */
    public function verify(GoodsReceipt $penerimaan, string $outcome, ?string $note, User $actor): GoodsReceipt
    {
        if ($penerimaan->isVerified()) {
            throw new KitchenException('Penerimaan ini sudah diverifikasi sebelumnya.');
        }

        $penerimaan->update([
            'status' => GoodsReceipt::STATUS_TERVERIFIKASI,
            'verified_by' => $actor->id,
            'verified_at' => now(),
            'verification_outcome' => $outcome,
            'verification_note' => $note,
        ]);

        return $penerimaan->refresh();
    }
}
