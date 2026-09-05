<?php

namespace App\Modules\Pharmacy\Services;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Layar gabungan "Laporan Penggunaan Obat" — item 6 (terakhir domain D),
 * menaungi 6 kode Khanza yang genuinely laporan baru (beda dari 5 kode
 * item 6 lain yang sudah cukup lewat StockReportService/PharmacyRecapService
 * yang sudah ada, lihat catatan PharmacyRecapService): pengguna_obat_resep,
 * obat_per_resep, obat10_terbanyak_poli, rekap_obat_poli, rekap_obat_pasien,
 * ringkasan_biaya_obat_pasien_pertanggal. Digerbangi rekap_obat_pasien.
 *
 * Semua baca pharmacy.prescriptions + prescription_items berstatus
 * 'diserahkan' — obat yang benar-benar sampai ke pasien, bukan yang
 * baru ditulis/ditelaah. Tidak termasuk penjualan bebas (retail_sales,
 * sudah punya rekapnya sendiri di PharmacyRecapService) karena kode-kode
 * ini semua berbasis resep ("_resep").
 *
 * obat_per_resep ditafsirkan sebagai "obat dikelompokkan per DOKTER
 * peresep" (prescriber_name) — nama Delphi-nya DlgObatPeresep ("obat
 * per-peresep"), bukan makna literal "per lembar resep" yang kurang
 * berguna sebagai laporan (satu resep bisa banyak baris obat, jadi
 * "per resep" saja tidak banyak makna sebagai pengelompokan).
 */
class DrugUsageReportService
{
    /** rekap_obat_pasien — dikelompokkan per pasien. */
    public function byPatient(string $dari, string $sampai, ?string $careType = null): Collection
    {
        return $this->dasarQuery($dari, $sampai, $careType)
            ->selectRaw('p.patient_mrn, p.patient_name, count(distinct p.id) as jumlah_resep, sum(i.dispensed_quantity) as jumlah_unit, sum(i.dispensed_quantity * i.unit_price) as total_biaya')
            ->groupBy('p.patient_mrn', 'p.patient_name')
            ->orderByDesc('total_biaya')
            ->get();
    }

    /** pengguna_obat_resep — dikelompokkan per obat, menunjukkan berapa pasien berbeda yang menerimanya (penelusuran obat, mis. untuk narkotika/psikotropika). */
    public function byDrug(string $dari, string $sampai, ?string $careType = null): Collection
    {
        return $this->dasarQuery($dari, $sampai, $careType)
            ->selectRaw('d.name as drug_name, count(distinct p.patient_mrn) as jumlah_pasien, sum(i.dispensed_quantity) as jumlah_unit')
            ->groupBy('d.name')
            ->orderByDesc('jumlah_unit')
            ->get();
    }

    /** obat_per_resep — dikelompokkan per dokter peresep, lihat catatan kelas. */
    public function byPrescriber(string $dari, string $sampai, ?string $careType = null): Collection
    {
        return $this->dasarQuery($dari, $sampai, $careType)
            ->selectRaw("coalesce(p.prescriber_name, '—') as prescriber_name, count(distinct p.id) as jumlah_resep, sum(i.dispensed_quantity * i.unit_price) as total_biaya")
            ->groupBy('p.prescriber_name')
            ->orderByDesc('total_biaya')
            ->get();
    }

    /** obat10_terbanyak_poli — 10 obat terbanyak, opsional difilter satu unit. */
    public function top10(string $dari, string $sampai, ?string $unitName = null, ?string $careType = null): Collection
    {
        $query = $this->dasarQuery($dari, $sampai, $careType)
            ->selectRaw('d.name as drug_name, sum(i.dispensed_quantity) as jumlah_unit')
            ->groupBy('d.name')
            ->orderByDesc('jumlah_unit')
            ->limit(10);

        if ($unitName !== null) {
            $query->where('p.unit_name', $unitName);
        }

        return $query->get();
    }

    /** rekap_obat_poli — dikelompokkan per unit/poliklinik. */
    public function byUnit(string $dari, string $sampai, ?string $careType = null): Collection
    {
        return $this->dasarQuery($dari, $sampai, $careType)
            ->selectRaw('p.unit_name, count(distinct p.id) as jumlah_resep, sum(i.dispensed_quantity * i.unit_price) as total_biaya')
            ->groupBy('p.unit_name')
            ->orderByDesc('total_biaya')
            ->get();
    }

    /** ringkasan_biaya_obat_pasien_pertanggal — biaya obat per pasien per tanggal. */
    public function biayaPerTanggal(string $dari, string $sampai, ?string $careType = null): Collection
    {
        return $this->dasarQuery($dari, $sampai, $careType)
            ->selectRaw('p.prescribed_at::date as tanggal, p.patient_mrn, p.patient_name, sum(i.dispensed_quantity * i.unit_price) as total_biaya')
            ->groupBy('tanggal', 'p.patient_mrn', 'p.patient_name')
            ->orderByDesc('tanggal')
            ->get();
    }

    /**
     * Domain I item D: penyaring jenis rawat & penjamin ditambahkan supaya
     * kode obat_per_dokter_ralan, obat_per_dokter_ranap, obat_per_kamar,
     * dan obat_per_cara_bayar terlayani di sini — bukan dibangun ulang di
     * billing (dikonfirmasi user). Katalog menandainya context=billing,
     * tapi datanya resep dan laporannya sudah ada di sini; laporan kembar
     * di dua konteks hanya akan berselisih lama-lama.
     *
     * Jenis rawat dan penjamin diambil dari encounter.v_registration_summary,
     * kontrak terbitan konteks encounter — resep sendiri tidak menyimpannya.
     */
    /** obat_per_cara_bayar — dikelompokkan per penjamin. */
    public function byPayer(string $dari, string $sampai, ?string $careType = null): Collection
    {
        return $this->dasarQuery($dari, $sampai, $careType, butuhPenjamin: true)
            ->selectRaw('r.payer_name, count(distinct p.id) as jumlah_resep, sum(i.dispensed_quantity * i.unit_price) as total_biaya')
            ->groupBy('r.payer_name')
            ->orderByDesc('total_biaya')
            ->get();
    }

    private function dasarQuery(string $dari, string $sampai, ?string $careType = null, bool $butuhPenjamin = false): Builder
    {
        $akhir = $sampai . ' 23:59:59';

        $query = DB::table('pharmacy.prescriptions as p')
            ->join('pharmacy.prescription_items as i', 'i.prescription_id', '=', 'p.id')
            ->join('pharmacy.drugs as d', 'd.id', '=', 'i.drug_id')
            ->where('p.status', 'diserahkan')
            ->whereBetween('p.prescribed_at', [$dari, $akhir]);

        if ($careType !== null || $butuhPenjamin) {
            $query->join('encounter.v_registration_summary as r', 'r.id', '=', 'p.registration_id');
        }

        if ($careType !== null) {
            $query->where('r.care_type', $careType);
        }

        return $query;
    }
}
