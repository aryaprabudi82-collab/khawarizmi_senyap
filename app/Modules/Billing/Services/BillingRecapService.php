<?php

namespace App\Modules\Billing\Services;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Rekap billing (domain I item D).
 *
 * Kunci item ini: hampir seluruh kode laporannya ternyata pengelompokan
 * dari dua tabel yang sudah ada — billing.charge_lines (apa yang
 * ditagihkan) dan billing.payments (apa yang dibayar). Jadi bukan
 * belasan laporan berbeda, melainkan beberapa potongan dari dua sumber.
 *
 * Sisi biaya (charge_lines), digerbangi ringkasan_tindakan:
 *   bySource        -> ringkasan_tindakan, rekap_biaya_registrasi, harian_kamar
 *   dailyBySource   -> harian_tindakan_poli, harian_tindakan_dokter, harian_kamar
 *   byUnit          -> harian_tindakan_poli per unit
 *   perPatient      -> jasa_tindakan_pasien, ringkasan_jasa_tindakan_medis
 *   detail          -> detail_tindakan, detail_tindakan_okvk
 *
 * Sisi pembayaran (payments), digerbangi rekap_pembayaran_ralan:
 *   paymentsDaily     -> rekap_pembayaran_ralan, rekap_pembayaran_ranap
 *   paymentsByUnit    -> pembayaran_per_unit, rekap_pembayaran_per_unit,
 *                        rekap_poli_anak (satu unit saja)
 *   paymentsByReceiver-> rekap_per_shift
 *
 * rekap_per_shift dikelompokkan per PETUGAS PENERIMA, bukan rentang jam
 * (dikonfirmasi user): saat tutup kas yang dicari adalah siapa memegang
 * berapa, dan itu tetap benar walaupun petugas bertukar giliran — hal
 * yang justru salah digambarkan oleh jam shift tetap.
 *
 * Pembayaran yang dibatalkan selalu dikecualikan: uangnya tidak pernah
 * jadi milik rumah sakit, jadi tidak boleh ikut terhitung di rekap mana
 * pun.
 */
class BillingRecapService
{
    /** Ringkasan biaya per jenis sumbernya — registrasi, kamar, tindakan, obat, dan seterusnya. */
    public function bySource(string $from, string $until, ?string $careType = null): Collection
    {
        return $this->chargeQuery($from, $until, $careType)
            ->groupBy('c.source_type')
            ->selectRaw('c.source_type, count(*) as jumlah_baris, sum(c.amount) as total')
            ->orderByDesc('total')
            ->get();
    }

    /** Satu jenis biaya, dirinci per hari. */
    public function dailyBySource(string $source, string $from, string $until, ?string $careType = null): Collection
    {
        return $this->chargeQuery($from, $until, $careType)
            ->where('c.source_type', $source)
            ->groupBy(DB::raw('c.charged_at::date'))
            ->selectRaw('c.charged_at::date as tanggal, count(*) as jumlah_baris, sum(c.amount) as total')
            ->orderBy('tanggal')
            ->get();
    }

    /** Biaya per unit/poliklinik. */
    public function byUnit(string $from, string $until, ?string $careType = null): Collection
    {
        return $this->chargeQuery($from, $until, $careType)
            ->groupBy('i.unit_name')
            ->selectRaw("coalesce(i.unit_name, '—') as unit_name, count(distinct i.id) as jumlah_tagihan, sum(c.amount) as total")
            ->orderByDesc('total')
            ->get();
    }

    /** Jasa/biaya yang menempel pada tiap pasien — jasa_tindakan_pasien. */
    public function perPatient(string $from, string $until, ?string $source = null, ?string $careType = null): Collection
    {
        $query = $this->chargeQuery($from, $until, $careType);

        if ($source !== null) {
            $query->where('c.source_type', $source);
        }

        return $query
            ->groupBy('i.patient_mrn', 'i.patient_name')
            ->selectRaw('i.patient_mrn, i.patient_name, count(*) as jumlah_baris, sum(c.amount) as total')
            ->orderByDesc('total')
            ->limit(200)
            ->get();
    }

    /** Rincian baris biaya — detail_tindakan dan detail_tindakan_okvk (disaring source 'operasi'). */
    public function detail(string $from, string $until, ?string $source = null, ?string $careType = null): Collection
    {
        $query = $this->chargeQuery($from, $until, $careType);

        if ($source !== null) {
            $query->where('c.source_type', $source);
        }

        return $query
            ->selectRaw('c.charged_at, c.source_type, c.description, c.quantity, c.amount, i.patient_mrn, i.patient_name, i.unit_name')
            ->orderByDesc('c.charged_at')
            ->limit(500)
            ->get();
    }

    /** Pembayaran per hari — rekap_pembayaran_ralan / rekap_pembayaran_ranap. */
    public function paymentsDaily(string $from, string $until, ?string $careType = null): Collection
    {
        return $this->paymentQuery($from, $until, $careType)
            ->groupBy(DB::raw('y.paid_at::date'))
            ->selectRaw('y.paid_at::date as tanggal, count(*) as jumlah, sum(y.amount) as total')
            ->orderBy('tanggal')
            ->get();
    }

    /** Pembayaran per unit — pembayaran_per_unit, rekap_pembayaran_per_unit, rekap_poli_anak. */
    public function paymentsByUnit(string $from, string $until, ?string $careType = null): Collection
    {
        return $this->paymentQuery($from, $until, $careType)
            ->groupBy('i.unit_name')
            ->selectRaw("coalesce(i.unit_name, '—') as unit_name, count(*) as jumlah, sum(y.amount) as total")
            ->orderByDesc('total')
            ->get();
    }

    /** rekap_per_shift — per petugas penerima, lihat catatan kelas. */
    public function paymentsByReceiver(string $from, string $until, ?string $careType = null): Collection
    {
        return $this->paymentQuery($from, $until, $careType)
            ->groupBy('y.received_by', 'y.received_by_name')
            ->selectRaw("y.received_by, coalesce(y.received_by_name, '—') as received_by_name, count(*) as jumlah, sum(y.amount) as total")
            ->orderByDesc('total')
            ->get();
    }

    private function chargeQuery(string $from, string $until, ?string $careType): Builder
    {
        $query = DB::table('billing.charge_lines as c')
            ->join('billing.invoices as i', 'i.id', '=', 'c.invoice_id')
            ->whereBetween(DB::raw('c.charged_at::date'), [$from, $until])
            ->where('i.status', '!=', 'void');

        if ($careType !== null) {
            $query->where('i.care_type', $careType);
        }

        return $query;
    }

    private function paymentQuery(string $from, string $until, ?string $careType): Builder
    {
        $query = DB::table('billing.payments as y')
            ->join('billing.invoices as i', 'i.id', '=', 'y.invoice_id')
            ->whereBetween(DB::raw('y.paid_at::date'), [$from, $until])
            ->whereNull('y.voided_at');

        if ($careType !== null) {
            $query->where('i.care_type', $careType);
        }

        return $query;
    }
}
