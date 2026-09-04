<?php

namespace App\Modules\Pharmacy\Services;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Layar gabungan "Rekap Penjualan & Untung Farmasi" — menaungi 9 kode
 * Khanza yang semuanya laporan: keuntungan_penjualan, keuntungan_beri_obat,
 * keuntungan_beri_obat_nonpiutang, ringkasan_penjualan_obat,
 * ringkasan_retur_pembeli_obat, ringkasan_piutang_obat,
 * ringkasan_stok_keluar_obat, ringkasan_beri_obat, ringkasan_hibah_obat.
 * Digerbangi keuntungan_penjualan (item 5 juga, PharmacyRecapController).
 *
 * beriObatKeuntungan() adalah pendekatan Wave 1 yang disengaja: resep
 * tidak menyimpan snapshot HPP per baris (beda dari retail_sale_items
 * yang memang menyimpannya), jadi biaya pokok dihitung dari rata-rata
 * cost_price batch AKTIF obat itu SEKARANG — bukan HPP sungguhan saat
 * resep diserahkan. Keuntungan_beri_obat vs _nonpiutang dibedakan lewat
 * penjamin kunjungan (Umum/bayar sendiri dianggap padanan "nonpiutang",
 * penjamin lain padanan "piutang" karena ditagih belakangan) — bukan
 * status piutang literal, resep tidak punya konsep piutang sendiri.
 */
class PharmacyRecapService
{
    /** keuntungan_penjualan + ringkasan_penjualan_obat + ringkasan_retur_pembeli_obat + ringkasan_piutang_obat. */
    public function penjualanRingkasan(string $dari, string $sampai): array
    {
        $akhir = $sampai . ' 23:59:59';

        $penjualan = DB::table('pharmacy.retail_sales as s')
            ->join('pharmacy.retail_sale_items as i', 'i.sale_id', '=', 's.id')
            ->whereBetween('s.sold_at', [$dari, $akhir])
            ->selectRaw('count(distinct s.id) as jumlah_transaksi,
                sum(i.quantity * i.unit_price) as omzet,
                sum(i.quantity * i.cost_price) as hpp')
            ->first();

        $piutang = DB::table('pharmacy.retail_sales')
            ->where('payment_status', 'piutang')
            ->whereIn('status', ['selesai', 'retur-sebagian'])
            ->selectRaw('count(*) as jumlah, sum(total_amount - paid_amount) as sisa')
            ->first();

        $retur = DB::table('pharmacy.retail_sale_returns as r')
            ->join('pharmacy.retail_sale_return_items as i', 'i.sale_return_id', '=', 'r.id')
            ->whereBetween('r.returned_at', [$dari, $akhir])
            ->selectRaw('count(distinct r.id) as jumlah_retur, sum(i.quantity) as jumlah_unit')
            ->first();

        return [
            'jumlah_transaksi' => (int) $penjualan->jumlah_transaksi,
            'omzet' => (float) $penjualan->omzet,
            'hpp' => (float) $penjualan->hpp,
            'untung' => (float) $penjualan->omzet - (float) $penjualan->hpp,
            'piutang_jumlah' => (int) $piutang->jumlah,
            'piutang_sisa' => (float) $piutang->sisa,
            'retur_jumlah' => (int) $retur->jumlah_retur,
            'retur_unit' => (float) $retur->jumlah_unit,
        ];
    }

    /** keuntungan_beri_obat + keuntungan_beri_obat_nonpiutang + ringkasan_beri_obat — lihat catatan kelas soal pendekatan Wave 1. */
    public function beriObatKeuntungan(string $dari, string $sampai): array
    {
        $akhir = $sampai . ' 23:59:59';

        $baris = DB::table('pharmacy.prescriptions as p')
            ->join('pharmacy.prescription_items as i', 'i.prescription_id', '=', 'p.id')
            ->join('pharmacy.drugs as d', 'd.id', '=', 'i.drug_id')
            ->where('p.status', 'diserahkan')
            ->whereBetween('p.prescribed_at', [$dari, $akhir])
            ->selectRaw("p.id as prescription_id, p.unit_name,
                i.dispensed_quantity, i.unit_price, d.id as drug_id,
                coalesce((select avg(cost_price) from pharmacy.stock_batches where drug_id = d.id), 0) as rata_hpp")
            ->get();

        $omzet = (float) $baris->sum(fn ($b) => (float) $b->dispensed_quantity * (float) $b->unit_price);
        $hpp = (float) $baris->sum(fn ($b) => (float) $b->dispensed_quantity * (float) $b->rata_hpp);

        return [
            'jumlah_resep' => $baris->pluck('prescription_id')->unique()->count(),
            'omzet' => $omzet,
            'hpp' => $hpp,
            'untung' => $omzet - $hpp,
        ];
    }

    /** ringkasan_stok_keluar_obat — seluruh movement kind 'keluar' dalam rentang, dikelompokkan per obat. */
    public function stokKeluarRingkasan(string $dari, string $sampai): Collection
    {
        $akhir = $sampai . ' 23:59:59';

        return DB::table('pharmacy.stock_movements as m')
            ->join('pharmacy.drugs as d', 'd.id', '=', 'm.drug_id')
            ->where('m.kind', 'keluar')
            ->whereBetween('m.moved_at', [$dari, $akhir])
            ->selectRaw('d.name as drug_name, sum(-m.quantity) as jumlah')
            ->groupBy('d.name')
            ->orderByDesc('jumlah')
            ->get();
    }

    /** ringkasan_hibah_obat. */
    public function hibahRingkasan(string $dari, string $sampai): array
    {
        $akhir = $sampai . ' 23:59:59';

        $hasil = DB::table('pharmacy.donation_receipts as h')
            ->join('pharmacy.donation_receipt_items as i', 'i.donation_receipt_id', '=', 'h.id')
            ->whereBetween('h.received_at', [$dari, $akhir])
            ->selectRaw('count(distinct h.id) as jumlah_penerimaan, sum(i.quantity) as jumlah_unit')
            ->first();

        return [
            'jumlah_penerimaan' => (int) $hasil->jumlah_penerimaan,
            'jumlah_unit' => (float) $hasil->jumlah_unit,
        ];
    }
}
