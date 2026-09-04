<?php

namespace App\Modules\Pharmacy\Services;

use App\Modules\Pharmacy\Models\GoodsReceipt;
use App\Modules\Pharmacy\Models\PurchaseOrder;
use App\Modules\Pharmacy\Models\StockLocation;
use App\Modules\Platform\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * bayar_pemesanan_obat — terima barang dari suplier sekaligus catat info
 * pembayaran (lihat catatan migrasi soal dua dialog Khanza yang digerbangi
 * satu access flag ini). Barang selalu diterima ke lokasi GUDANG (gudang
 * pusat farmasi) lewat StockLedger::receive() yang sudah ada dan teruji —
 * mutasi ke depo ruangan menyusul di item 3 sub-order (mutasi_barang).
 *
 * verifikasi_penerimaan_farmasi adalah audit QC SETELAH barang diterima
 * (stok sudah tersedia begitu diterima, bukan menunggu verifikasi) —
 * beda dari pola envlab yang verifikasinya baru membuka tahap berikut.
 */
class GoodsReceiptService
{
    private const GUDANG_CODE = 'GUDANG';

    public function __construct(
        private readonly NumberAllocator $numbers,
        private readonly PurchaseOrderService $orders,
        private readonly StockLedger $ledger,
    ) {}

    /** @param array<int, array{purchase_order_item_id: int, drug_id: int, quantity: float, batch_number: string, expiry_date: ?string, cost_price: float}> $items */
    public function receive(PurchaseOrder $po, array $items, array $payment, User $actor): GoodsReceipt
    {
        if (! in_array($po->status, [PurchaseOrder::STATUS_DIPESAN, PurchaseOrder::STATUS_DITERIMA_SEBAGIAN], true)) {
            throw new PharmacyException('PO harus berstatus dipesan atau diterima sebagian sebelum barangnya diterima.');
        }

        if ($items === []) {
            throw new PharmacyException('Penerimaan harus berisi minimal satu obat/BHP.');
        }

        $gudang = StockLocation::query()->where('code', self::GUDANG_CODE)->firstOrFail();

        return DB::transaction(function () use ($po, $items, $payment, $actor, $gudang): GoodsReceipt {
            $penerimaan = GoodsReceipt::query()->create([
                'receipt_number' => $this->numbers->allocate('TRM'),
                'purchase_order_id' => $po->id,
                'received_at' => now(),
                'received_by' => $actor->id,
                'invoice_number' => $payment['invoice_number'] ?? null,
                'paid_amount' => $payment['paid_amount'] ?? 0,
                'payment_status' => ($payment['paid_amount'] ?? 0) > 0 ? GoodsReceipt::PAYMENT_LUNAS : GoodsReceipt::PAYMENT_BELUM_BAYAR,
                'status' => GoodsReceipt::STATUS_DITERIMA,
            ]);

            foreach ($items as $baris) {
                $quantity = (float) $baris['quantity'];

                if ($quantity <= 0) {
                    continue;
                }

                $penerimaan->items()->create([
                    'purchase_order_item_id' => $baris['purchase_order_item_id'] ?? null,
                    'drug_id' => $baris['drug_id'],
                    'quantity_received' => $quantity,
                    'batch_number' => $baris['batch_number'],
                    'expiry_date' => $baris['expiry_date'] ?? null,
                    'cost_price' => $baris['cost_price'] ?? 0,
                ]);

                $this->ledger->receive(
                    drugId: $baris['drug_id'],
                    locationId: $gudang->id,
                    batchNumber: $baris['batch_number'],
                    quantity: $quantity,
                    expiryDate: $baris['expiry_date'] ?? null,
                    costPrice: (float) ($baris['cost_price'] ?? 0),
                    actor: $actor,
                    note: "Penerimaan {$penerimaan->receipt_number} / PO {$po->po_number}",
                );

                if (! empty($baris['purchase_order_item_id'])) {
                    DB::table('pharmacy.purchase_order_items')
                        ->where('id', $baris['purchase_order_item_id'])
                        ->increment('quantity_received', $quantity);
                }
            }

            $this->orders->refreshReceivingStatus($po);

            return $penerimaan->fresh('items');
        });
    }

    /** verifikasi_penerimaan_farmasi — QC pasca-terima, tidak mengubah stok. */
    public function verify(GoodsReceipt $penerimaan, string $outcome, ?string $note, User $actor): GoodsReceipt
    {
        if ($penerimaan->isVerified()) {
            throw new PharmacyException('Penerimaan ini sudah diverifikasi sebelumnya.');
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
