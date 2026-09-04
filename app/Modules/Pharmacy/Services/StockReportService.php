<?php

namespace App\Modules\Pharmacy\Services;

use App\Modules\Pharmacy\Models\StockBatch;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Layar gabungan "Laporan Stok Farmasi" — menaungi 12 kode Khanza yang
 * semuanya laporan/browsing atas pharmacy.stock_batches &
 * pharmacy.stock_movements yang sudah ada (bukan tabel baru):
 * sisa_stok, darurat_stok, data_batch, kadaluarsa_batch,
 * riwayat_data_batch, obat_bhp_tidakbergerak, stok_akhir_farmasi_pertanggal,
 * sirkulasi_obat s/d sirkulasi_obat6 (6 varian Khanza, satu tabel
 * pergerakan yang bisa difilter menutupi semuanya — lihat catatan
 * migrasi 2026_09_28_000001).
 */
class StockReportService
{
    /** sisa_stok — saldo saat ini per obat per lokasi, hanya batch usable() (belum kedaluwarsa). */
    public function currentStock(?int $locationId = null): Collection
    {
        $query = DB::table('pharmacy.stock_batches as b')
            ->join('pharmacy.drugs as d', 'd.id', '=', 'b.drug_id')
            ->join('pharmacy.stock_locations as l', 'l.id', '=', 'b.location_id')
            ->where('b.quantity_on_hand', '>', 0)
            ->where(fn ($q) => $q->whereNull('b.expiry_date')->orWhere('b.expiry_date', '>=', now()->toDateString()))
            ->selectRaw('b.drug_id, d.name as drug_name, d.unit, d.minimum_stock, b.location_id, l.name as location_name, sum(b.quantity_on_hand) as total')
            ->groupBy('b.drug_id', 'd.name', 'd.unit', 'd.minimum_stock', 'b.location_id', 'l.name')
            ->orderBy('d.name');

        if ($locationId !== null) {
            $query->where('b.location_id', $locationId);
        }

        return $query->get();
    }

    /** darurat_stok — total saldo per obat (lintas lokasi) di bawah minimum_stock. */
    public function lowStock(): Collection
    {
        return $this->currentStock()
            ->groupBy('drug_id')
            ->map(function (Collection $baris) {
                $pertama = $baris->first();

                return (object) [
                    'drug_id' => $pertama->drug_id,
                    'drug_name' => $pertama->drug_name,
                    'unit' => $pertama->unit,
                    'minimum_stock' => $pertama->minimum_stock,
                    'total' => $baris->sum('total'),
                ];
            })
            ->filter(fn ($r) => (float) $r->minimum_stock > 0 && (float) $r->total < (float) $r->minimum_stock)
            ->sortBy('drug_name')
            ->values();
    }

    /** data_batch — seluruh batch dengan saldo > 0, terbaru dulu. */
    public function batches(?int $locationId = null): Collection
    {
        $query = StockBatch::query()->with(['drug', 'location'])->where('quantity_on_hand', '>', 0)->orderBy('expiry_date');

        if ($locationId !== null) {
            $query->where('location_id', $locationId);
        }

        return $query->get();
    }

    /** kadaluarsa_batch — reuse StockLedger::expiringSoon(), tidak ditulis ulang. */
    public function expiring(int $locationId, int $days = 90): Collection
    {
        return app(StockLedger::class)->expiringSoon($locationId, $days);
    }

    /** riwayat_data_batch — buku besar satu batch, terbaru dulu. */
    public function batchHistory(int $batchId): Collection
    {
        return DB::table('pharmacy.stock_movements')->where('batch_id', $batchId)->orderByDesc('moved_at')->get();
    }

    /** obat_bhp_tidakbergerak — batch dengan saldo > 0 tapi tidak ada movement dalam $days terakhir. */
    public function nonMoving(int $days = 90): Collection
    {
        $batas = now()->subDays($days);

        return StockBatch::query()
            ->with(['drug', 'location'])
            ->where('quantity_on_hand', '>', 0)
            ->whereNotExists(function ($q) use ($batas) {
                $q->select(DB::raw(1))
                    ->from('pharmacy.stock_movements as m')
                    ->whereColumn('m.batch_id', 'pharmacy.stock_batches.id')
                    ->where('m.moved_at', '>=', $batas);
            })
            ->orderBy('updated_at')
            ->get();
    }

    /** stok_akhir_farmasi_pertanggal — saldo akhir per batch pada satu tanggal, dibaca dari balance_after movement terakhir sebelum/pada tanggal itu (bukan quantity_on_hand saat ini). */
    public function balanceAsOf(string $date, ?int $locationId = null): Collection
    {
        $akhirHari = $date . ' 23:59:59';

        $query = DB::table('pharmacy.stock_movements as m')
            ->join('pharmacy.stock_batches as b', 'b.id', '=', 'm.batch_id')
            ->join('pharmacy.drugs as d', 'd.id', '=', 'm.drug_id')
            ->whereIn('m.id', function ($sub) use ($akhirHari) {
                $sub->select(DB::raw('max(id)'))
                    ->from('pharmacy.stock_movements')
                    ->where('moved_at', '<=', $akhirHari)
                    ->groupBy('batch_id');
            })
            ->select('b.id as batch_id', 'b.batch_number', 'd.name as drug_name', 'd.unit', 'm.location_id', 'm.balance_after')
            ->orderBy('d.name');

        if ($locationId !== null) {
            $query->where('m.location_id', $locationId);
        }

        return $query->get();
    }

    /**
     * sirkulasi_obat (s/d sirkulasi_obat6) — buku besar pergerakan
     * terfilter. 6 varian menu Khanza semuanya membaca tabel yang sama
     * (stock_movements), makanya satu method dengan filter sudah cukup
     * dibanding 6 query terpisah.
     *
     * @param array{drug_id?: int, location_id?: int, kind?: string, dari?: string, sampai?: string} $filter
     */
    public function circulation(array $filter = []): Collection
    {
        $query = DB::table('pharmacy.stock_movements as m')
            ->join('pharmacy.drugs as d', 'd.id', '=', 'm.drug_id')
            ->join('pharmacy.stock_locations as l', 'l.id', '=', 'm.location_id')
            ->select('m.*', 'd.name as drug_name', 'l.name as location_name')
            ->orderByDesc('m.moved_at')
            ->limit(200);

        if (! empty($filter['drug_id'])) {
            $query->where('m.drug_id', $filter['drug_id']);
        }
        if (! empty($filter['location_id'])) {
            $query->where('m.location_id', $filter['location_id']);
        }
        if (! empty($filter['kind'])) {
            $query->where('m.kind', $filter['kind']);
        }
        if (! empty($filter['dari'])) {
            $query->where('m.moved_at', '>=', $filter['dari']);
        }
        if (! empty($filter['sampai'])) {
            $query->where('m.moved_at', '<=', $filter['sampai'] . ' 23:59:59');
        }

        return $query->get();
    }
}
