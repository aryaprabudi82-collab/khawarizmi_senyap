<?php

namespace App\Modules\Asset\Services;

use App\Modules\Asset\Models\GoodsReceipt;
use App\Modules\Asset\Models\PurchaseOrder;
use App\Modules\Platform\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * penerimaan_aset_inventaris — beda MENDASAR dari GoodsReceiptService
 * inventory/kitchen/pharmacy: TIDAK ada StockLedger/quantity_on_hand
 * di sini sama sekali. Tiap unit diterima memanggil
 * AssetService::createAsset() satu kali (aset dilacak per-unit lewat
 * asset_number sendiri-sendiri), bukan menambah kuantitas ke baris
 * yang sudah ada. Lihat catatan migrasi 2026_10_09_000001.
 */
class AssetGoodsReceiptService
{
    public function __construct(
        private readonly NumberAllocator $numbers,
        private readonly AssetService $assets,
        private readonly AssetPurchaseOrderService $orders,
    ) {}

    /** @param array<int, array{purchase_order_item_id: int, quantity: int}> $items */
    public function receive(PurchaseOrder $po, array $items, User $actor): GoodsReceipt
    {
        if (! in_array($po->status, [PurchaseOrder::STATUS_DIPESAN, PurchaseOrder::STATUS_DITERIMA_SEBAGIAN], true)) {
            throw new AssetException('PO harus berstatus dipesan atau diterima sebagian sebelum barangnya diterima.');
        }

        if ($items === []) {
            throw new AssetException('Penerimaan harus berisi minimal satu barang.');
        }

        return DB::transaction(function () use ($po, $items, $actor): GoodsReceipt {
            $penerimaan = GoodsReceipt::query()->create([
                'receipt_number' => $this->numbers->allocate('TRM'),
                'purchase_order_id' => $po->id,
                'received_at' => now(),
                'received_by' => $actor->id,
            ]);

            foreach ($items as $baris) {
                $quantity = (int) $baris['quantity'];

                if ($quantity <= 0) {
                    continue;
                }

                $poItem = $po->items()->whereKey($baris['purchase_order_item_id'])->firstOrFail();

                $penerimaan->items()->create([
                    'purchase_order_item_id' => $poItem->id,
                    'quantity_received' => $quantity,
                ]);

                // Satu baris PO bisa diterima > 1 unit — buat aset baru
                // sebanyak kuantitas diterima, masing-masing dapat
                // asset_number sendiri lewat AssetService::createAsset().
                for ($i = 0; $i < $quantity; $i++) {
                    $this->assets->createAsset([
                        'name' => $poItem->item_name,
                        'category_id' => $poItem->category_id,
                        'type_id' => $poItem->type_id,
                        'manufacturer_id' => $poItem->manufacturer_id,
                        'brand' => $poItem->brand,
                        'acquisition_date' => now()->toDateString(),
                        'acquisition_value' => $poItem->unit_price,
                    ]);
                }

                DB::table('asset.purchase_order_items')
                    ->where('id', $poItem->id)
                    ->increment('quantity_received', $quantity);
            }

            $this->orders->refreshReceivingStatus($po);

            return $penerimaan->fresh('items');
        });
    }
}
