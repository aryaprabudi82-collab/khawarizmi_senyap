<?php

namespace App\Modules\Inventory\Services;

use App\Modules\Inventory\Models\GoodsReceipt;
use App\Modules\Inventory\Models\PurchaseOrder;
use App\Modules\Platform\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * penerimaan_non_medis — terima barang dari suplier. Lewat
 * StockLedger::receive() (source='pembelian') yang sudah ada, bukan
 * ditulis manual. Tidak ada konsep pembayaran gabungan di sini (beda
 * dari farmasi/bayar_pemesanan_obat) — dikonfirmasi lewat xlsx,
 * penerimaan_non_medis cuma satu baris paket "ipsrs", tidak dipakai
 * ulang di domain K/keuangan seperti pasangan farmasinya.
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
            throw new InventoryException('PO harus berstatus dipesan atau diterima sebagian sebelum barangnya diterima.');
        }

        if ($items === []) {
            throw new InventoryException('Penerimaan harus berisi minimal satu barang.');
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
                    DB::table('inventory.purchase_order_items')
                        ->where('id', $baris['purchase_order_item_id'])
                        ->increment('quantity_received', $quantity);
                }
            }

            $this->orders->refreshReceivingStatus($po);

            return $penerimaan->fresh('items');
        });
    }

    /** verifikasi_penerimaan_logistik — QC pasca-terima, tidak mengubah stok. */
    public function verify(GoodsReceipt $penerimaan, string $outcome, ?string $note, User $actor): GoodsReceipt
    {
        if ($penerimaan->isVerified()) {
            throw new InventoryException('Penerimaan ini sudah diverifikasi sebelumnya.');
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
