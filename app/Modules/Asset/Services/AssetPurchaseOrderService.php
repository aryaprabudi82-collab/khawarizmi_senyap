<?php

namespace App\Modules\Asset\Services;

use App\Modules\Asset\Models\PurchaseOrder;
use App\Modules\Asset\Models\Requisition;
use Illuminate\Support\Facades\DB;

/**
 * pengadaan_aset_inventaris — PO ke suplier untuk aset baru, boleh
 * berasal dari pengajuan unit atau berdiri sendiri. Tidak ada
 * surat_pemesanan_aset/kode cetak terpisah di domain G (beda dari
 * domain E/F) — dikonfirmasi lewat xlsx, tidak ada padanan
 * surat_pemesanan_non_medis/surat_pemesanan_dapur untuk aset.
 */
class AssetPurchaseOrderService
{
    public function __construct(private readonly NumberAllocator $numbers) {}

    /** @param array<int, array{item_name: string, category_id: int, type_id: ?int, manufacturer_id: ?int, brand: ?string, quantity: float, unit_price: float}> $items */
    public function create(int $supplierId, array $items, int $createdBy, ?Requisition $requisition = null): PurchaseOrder
    {
        if ($items === []) {
            throw new AssetException('PO harus berisi minimal satu barang.');
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

            foreach ($items as $baris) {
                $quantity = (float) $baris['quantity'];

                if ($quantity <= 0) {
                    continue;
                }

                $total += $quantity * (float) $baris['unit_price'];

                $po->items()->create([
                    'item_name' => $baris['item_name'],
                    'category_id' => $baris['category_id'],
                    'type_id' => $baris['type_id'] ?? null,
                    'manufacturer_id' => $baris['manufacturer_id'] ?? null,
                    'brand' => $baris['brand'] ?? null,
                    'quantity_ordered' => $quantity,
                    'unit_price' => $baris['unit_price'],
                ]);
            }

            $po->update(['total_amount' => $total]);

            // rekap_pengajuan_aset_departemen dibaca dari requisitions,
            // bukan status PO — jadi menandai requisition 'selesai' di
            // sini cukup, tidak perlu menunggu barang sungguhan diterima.
            if ($requisition !== null) {
                $requisition->update(['status' => Requisition::STATUS_SELESAI]);
            }

            return $po->fresh('items');
        });
    }

    public function submit(PurchaseOrder $po): PurchaseOrder
    {
        if ($po->status !== PurchaseOrder::STATUS_DRAF) {
            throw new AssetException('Hanya PO berstatus draf yang bisa dikirim ke suplier.');
        }

        $po->update(['status' => PurchaseOrder::STATUS_DIPESAN, 'ordered_at' => now()]);

        return $po->refresh();
    }

    public function cancel(PurchaseOrder $po): PurchaseOrder
    {
        if (in_array($po->status, [PurchaseOrder::STATUS_DITERIMA, PurchaseOrder::STATUS_DIBATALKAN], true)) {
            throw new AssetException('PO yang sudah diterima penuh atau sudah dibatalkan tidak bisa dibatalkan lagi.');
        }

        $po->update(['status' => PurchaseOrder::STATUS_DIBATALKAN]);

        return $po->refresh();
    }

    /** Dipanggil AssetGoodsReceiptService setelah barang diterima — load() paksa, bukan loadMissing(), lihat catatan yang sama di Inventory/Kitchen\PurchaseOrderService. */
    public function refreshReceivingStatus(PurchaseOrder $po): void
    {
        $po->load('items');

        $sisaTotal = $po->items->sum(fn ($baris) => $baris->remainingQuantity());

        $po->update([
            'status' => $sisaTotal <= 0 ? PurchaseOrder::STATUS_DITERIMA : PurchaseOrder::STATUS_DITERIMA_SEBAGIAN,
        ]);
    }
}
