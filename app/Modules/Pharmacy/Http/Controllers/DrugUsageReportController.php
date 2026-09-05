<?php

namespace App\Modules\Pharmacy\Http\Controllers;

use App\Modules\Pharmacy\Services\DrugUsageReportService;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Menaungi pengguna_obat_resep, obat_per_resep, obat10_terbanyak_poli,
 * rekap_obat_poli, rekap_obat_pasien, ringkasan_biaya_obat_pasien_pertanggal
 * — lihat catatan DrugUsageReportService.
 *
 * Sejak domain I item D juga menaungi enam kode obat_per_* yang di katalog
 * tertulis context=billing (dikonfirmasi user): obat_per_poli dan
 * obat_per_dokter_peresep sudah terpenuhi byUnit/byPrescriber sejak awal,
 * obat_per_dokter_ralan/ranap dan obat_per_kamar lewat penyaring jenis
 * rawat, obat_per_cara_bayar lewat byPayer. Tidak ada gerbang baru —
 * seluruhnya di layar yang sama, digerbangi rekap_obat_pasien.
 */
class DrugUsageReportController
{
    public function __construct(private readonly DrugUsageReportService $reports) {}

    public function index(Request $request): View
    {
        $dari = $request->query('dari', now()->startOfMonth()->toDateString());
        $sampai = $request->query('sampai', now()->toDateString());
        $unit = $request->query('unit');

        $jenisRawat = in_array($request->query('jenis_rawat'), ['ralan', 'ranap'], true)
            ? $request->query('jenis_rawat')
            : null;

        return view('pharmacy::laporan-obat.index', [
            'dari' => $dari,
            'sampai' => $sampai,
            'unitFilter' => $unit,
            'jenisRawat' => $jenisRawat,
            'perPasien' => $this->reports->byPatient($dari, $sampai),
            'perObat' => $this->reports->byDrug($dari, $sampai),
            'perDokter' => $this->reports->byPrescriber($dari, $sampai, $jenisRawat),
            'top10' => $this->reports->top10($dari, $sampai, $unit),
            'perUnit' => $this->reports->byUnit($dari, $sampai, $jenisRawat),
            'perPenjamin' => $this->reports->byPayer($dari, $sampai),
            'biayaPerTanggal' => $this->reports->biayaPerTanggal($dari, $sampai),
        ]);
    }
}
