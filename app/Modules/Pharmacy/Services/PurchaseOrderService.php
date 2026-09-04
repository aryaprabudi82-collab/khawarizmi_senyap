<?php

namespace App\Modules\Pharmacy\Services;

use App\Modules\Pharmacy\Models\Drug;
use App\Modules\Pharmacy\Models\DrugRequisition;
use App\Modules\Pharmacy\Models\PurchaseOrder;
use Illuminate\Support\Facades\DB;

/**
 * pengadaan_obat (DlgPembelian) — PO ke suplier, boleh berasal dari
 * pengajuan unit atau berdiri sendiri. pemesanan_obat (Surat Pemesanan)
 * dilayani lewat PurchaseOrderController::print(), bukan method di sini
 * — cetak dokumen dari data yang sama, lihat catatan migrasi.
 */
class PurchaseOrderService
{
    public function __construct(private readonly NumberAllocator $numbers) {}

    /** @param array<int, array{quantity: float, unit: string, unit_price: float}> $items drugId => rincian */
    public function create(int $supplierId, array $items, int $createdBy, ?DrugRequisition $requisition = null): PurchaseOrder
    {
        if ($items === []) {
            throw new PharmacyException('PO harus berisi minimal satu obat/BHP.');
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

            foreach ($items as $drugId => $rincian) {
                $quantity = (float) $rincian['quantity'];

                if ($quantity <= 0) {
                    continue;
                }

                $drugName = Drug::query()->whereKey($drugId)->value('name') ?? '—';
                $subtotal = $quantity * (float) $rincian['unit_price'];
                $total += $subtotal;

                $po->items()->create([
                    'drug_id' => $drugId,
                    'drug_name' => $drugName,
                    'unit' => $rincian['unit'],
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
            throw new PharmacyException('Hanya PO berstatus draf yang bisa dikirim ke suplier.');
        }

        $po->update(['status' => PurchaseOrder::STATUS_DIPESAN, 'ordered_at' => now()]);

        return $po->refresh();
    }

    public function cancel(PurchaseOrder $po): PurchaseOrder
    {
        if (in_array($po->status, [PurchaseOrder::STATUS_DITERIMA, PurchaseOrder::STATUS_DIBATALKAN], true)) {
            throw new PharmacyException('PO yang sudah diterima penuh atau sudah dibatalkan tidak bisa dibatalkan lagi.');
        }

        $po->update(['status' => PurchaseOrder::STATUS_DIBATALKAN]);

        return $po->refresh();
    }

    /** Dipanggil GoodsReceiptService setelah barang diterima — menandai PO diterima penuh/sebagian berdasarkan sisa kuantitas tiap baris. */
    public function refreshReceivingStatus(PurchaseOrder $po): void
    {
        // load() paksa, bukan loadMissing() — dipanggil setelah GoodsReceiptService
        // menambah quantity_received lewat raw query, relasi items yang sudah
        // di-load sebelumnya (mis. di controller) harus dibaca ulang dari DB.
        $po->load('items');

        $sisaTotal = $po->items->sum(fn ($baris) => $baris->remainingQuantity());

        $po->update([
            'status' => $sisaTotal <= 0 ? PurchaseOrder::STATUS_DITERIMA : PurchaseOrder::STATUS_DITERIMA_SEBAGIAN,
        ]);
    }
}
