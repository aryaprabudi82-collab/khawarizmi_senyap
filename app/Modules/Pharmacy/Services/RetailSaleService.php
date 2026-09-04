<?php

namespace App\Modules\Pharmacy\Services;

use App\Modules\Pharmacy\Models\Drug;
use App\Modules\Pharmacy\Models\RetailSale;
use App\Modules\Pharmacy\Models\RetailSaleReturn;
use App\Modules\Pharmacy\Models\StockLocation;
use App\Modules\Platform\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * penjualan_obat + piutang_obat (dibedakan payment_status, lihat
 * catatan migrasi) dan retur_dari_pembeli + retur_piutang_pasien
 * (satu mekanisme retur, lihat retailSaleReturn()).
 */
class RetailSaleService
{
    private const DEPO_CODE = 'DEPO-RJ';

    public function __construct(
        private readonly NumberAllocator $numbers,
        private readonly StockLedger $ledger,
    ) {}

    /** @param array<int, array{quantity: float, unit_price: float}> $items drugId => rincian */
    public function sell(array $data, array $items, User $actor): RetailSale
    {
        if ($items === []) {
            throw new PharmacyException('Penjualan harus berisi minimal satu obat/BHP.');
        }

        $depo = StockLocation::query()->where('code', self::DEPO_CODE)->firstOrFail();

        return DB::transaction(function () use ($data, $items, $actor, $depo): RetailSale {
            $penjualan = RetailSale::query()->create($data + [
                'sale_number' => $this->numbers->allocate('JUL'),
                'status' => RetailSale::STATUS_SELESAI,
                'sold_at' => now(),
                'sold_by' => $actor->id,
            ]);

            $total = 0.0;

            foreach ($items as $drugId => $rincian) {
                $quantity = (float) $rincian['quantity'];

                if ($quantity <= 0) {
                    continue;
                }

                $subtotal = $quantity * (float) $rincian['unit_price'];
                $total += $subtotal;

                // Ambil dulu dari FEFO supaya cost_price yang disimpan adalah HPP batch yang sungguh diambil, bukan tebakan.
                $diambil = $this->ledger->issue(
                    drugId: $drugId,
                    locationId: $depo->id,
                    quantity: $quantity,
                    referenceType: 'retail-sale',
                    referenceId: null,
                    actor: $actor,
                );

                $totalCost = 0.0;
                foreach ($diambil as $baris) {
                    $totalCost += $baris['quantity'] * (float) $baris['batch']->cost_price;
                }
                $hpp = $quantity > 0 ? $totalCost / $quantity : 0.0;

                $penjualan->items()->create([
                    'drug_id' => $drugId,
                    'drug_name' => Drug::query()->whereKey($drugId)->value('name') ?? '—',
                    'quantity' => $quantity,
                    'unit_price' => $rincian['unit_price'],
                    'cost_price' => $hpp,
                ]);
            }

            $penjualan->update([
                'total_amount' => $total,
                'paid_amount' => $penjualan->payment_status === RetailSale::PAYMENT_LUNAS ? $total : 0,
            ]);

            return $penjualan->fresh('items');
        });
    }

    /** retur_dari_pembeli + retur_piutang_pasien — stok kembali (kind 'retur', sama seperti pembatalan penyerahan resep), status penjualan disesuaikan otomatis. */
    public function returnItems(RetailSale $penjualan, array $items, string $reason, User $actor): RetailSaleReturn
    {
        if (in_array($penjualan->status, [RetailSale::STATUS_RETUR_PENUH, RetailSale::STATUS_DIBATALKAN], true)) {
            throw new PharmacyException('Penjualan ini sudah diretur penuh atau dibatalkan.');
        }

        if ($items === []) {
            throw new PharmacyException('Retur harus berisi minimal satu baris.');
        }

        $depo = StockLocation::query()->where('code', self::DEPO_CODE)->firstOrFail();

        return DB::transaction(function () use ($penjualan, $items, $reason, $actor, $depo): RetailSaleReturn {
            $retur = RetailSaleReturn::query()->create([
                'return_number' => $this->numbers->allocate('JRT'),
                'sale_id' => $penjualan->id,
                'reason' => $reason,
                'returned_at' => now(),
                'returned_by' => $actor->id,
            ]);

            $penjualan->load('items');

            foreach ($items as $saleItemId => $quantity) {
                $quantity = (float) $quantity;

                if ($quantity <= 0) {
                    continue;
                }

                $baris = $penjualan->items->firstWhere('id', $saleItemId)
                    ?? throw new PharmacyException('Baris penjualan tidak ditemukan.');

                if ($quantity > $baris->remainingQuantity()) {
                    throw new PharmacyException("Jumlah retur {$baris->drug_name} melebihi sisa yang bisa diretur.");
                }

                $retur->items()->create(['sale_item_id' => $baris->id, 'quantity' => $quantity]);
                $baris->increment('quantity_returned', $quantity);

                // Baris penjualan tidak menyimpan batch asalnya (FEFO bisa
                // memecah satu penjualan ke beberapa batch) — barang yang
                // kembali masuk ke batch retur tersendiri per penjualan,
                // bukan dicari-carikan batch lama. Lewat receive() supaya
                // buku besar tetap konsisten (bukan menulis quantity_on_hand
                // langsung).
                $this->ledger->receive(
                    drugId: $baris->drug_id,
                    locationId: $depo->id,
                    batchNumber: 'RETUR-' . $penjualan->sale_number,
                    quantity: $quantity,
                    actor: $actor,
                    note: "Retur {$retur->return_number} dari {$penjualan->sale_number}",
                    kind: 'retur',
                    referenceType: 'retail-sale-return',
                    referenceId: $retur->id,
                );
            }

            $penjualan->refresh()->load('items');
            $totalDiminta = $penjualan->items->sum('quantity');
            $totalRetur = $penjualan->items->sum('quantity_returned');

            $penjualan->update([
                'status' => $totalRetur >= $totalDiminta ? RetailSale::STATUS_RETUR_PENUH : RetailSale::STATUS_RETUR_SEBAGIAN,
            ]);

            return $retur->fresh('items');
        });
    }
}
