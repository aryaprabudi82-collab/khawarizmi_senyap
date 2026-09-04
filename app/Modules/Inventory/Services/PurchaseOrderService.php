<?php

namespace App\Modules\Inventory\Services;

use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\PurchaseOrder;
use App\Modules\Inventory\Models\Requisition;
use Illuminate\Support\Facades\DB;

/**
 * ipsrs_pengadaan_barang — PO ke suplier, boleh berasal dari pengajuan
 * unit atau berdiri sendiri. surat_pemesanan_non_medis dilayani lewat
 * PurchaseOrderController::print(), bukan method di sini.
 */
class PurchaseOrderService
{
    public function __construct(private readonly NumberAllocator $numbers) {}

    /** @param array<int, array{quantity: float, unit_of_measure: string, unit_price: float}> $items itemId => rincian */
    public function create(int $supplierId, array $items, int $createdBy, ?Requisition $requisition = null): PurchaseOrder
    {
        if ($items === []) {
            throw new InventoryException('PO harus berisi minimal satu barang.');
        }

        return DB::transaction(function () use ($supplierId, $items, $createdBy, $requisition): PurchaseOrder {
            $po = PurchaseOrder::query()->create([
                'po_number' => $this->numbers->allocate('PO'),
                'supplier_id' => $supplierId,
                'requisition_id' => $requisition?->id,
                'status' => PurchaseOrder::STATUS_DRAF,
                'created_by' => $createdBy,
            ]);

            $total = 0.0;

            foreach ($items as $itemId => $rincian) {
                $quantity = (float) $rincian['quantity'];

                if ($quantity <= 0) {
                    continue;
                }

                $itemName = Item::query()->whereKey($itemId)->value('name') ?? '—';
                $subtotal = $quantity * (float) $rincian['unit_price'];
                $total += $subtotal;

                $po->items()->create([
                    'item_id' => $itemId,
                    'item_name' => $itemName,
                    'unit_of_measure' => $rincian['unit_of_measure'],
                    'quantity_ordered' => $quantity,
                    'unit_price' => $rincian['unit_price'],
                ]);
            }

            $po->update(['total_amount' => $total]);

            return $po->fresh('items');
        });
    }

    public function submit(PurchaseOrder $po): PurchaseOrder
    {
        if ($po->status !== PurchaseOrder::STATUS_DRAF) {
            throw new InventoryException('Hanya PO berstatus draf yang bisa dikirim ke suplier.');
        }

        $po->update(['status' => PurchaseOrder::STATUS_DIPESAN, 'ordered_at' => now()]);

        return $po->refresh();
    }

    public function cancel(PurchaseOrder $po): PurchaseOrder
    {
        if (in_array($po->status, [PurchaseOrder::STATUS_DITERIMA, PurchaseOrder::STATUS_DIBATALKAN], true)) {
            throw new InventoryException('PO yang sudah diterima penuh atau sudah dibatalkan tidak bisa dibatalkan lagi.');
        }

        $po->update(['status' => PurchaseOrder::STATUS_DIBATALKAN]);

        return $po->refresh();
    }

    /** Dipanggil GoodsReceiptService setelah barang diterima — load() paksa, bukan loadMissing(), lihat catatan yang sama di pharmacy\PurchaseOrderService. */
    public function refreshReceivingStatus(PurchaseOrder $po): void
    {
        $po->load('items');

        $sisaTotal = $po->items->sum(fn ($baris) => $baris->remainingQuantity());

        $po->update([
            'status' => $sisaTotal <= 0 ? PurchaseOrder::STATUS_DITERIMA : PurchaseOrder::STATUS_DITERIMA_SEBAGIAN,
        ]);
    }
}
