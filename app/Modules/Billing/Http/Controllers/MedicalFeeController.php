<?php

namespace App\Modules\Billing\Http\Controllers;

use App\Modules\Billing\Services\MedicalFeeReportService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\View\View;

/**
 * Rekap jasa medis (domain I item C). Satu layar menaungi ~14 kode
 * laporan Khanza yang sesungguhnya potongan berbeda dari data yang sama:
 * harian_* dan bulanan_* untuk enam komponen, plus rekap_jm_dokter.
 *
 * Digerbangi rekap_jm_dokter sebagai kode yang paling mewakili — pola
 * umbrella yang sama seperti ipsrs_rekap_pengadaan di domain E dan
 * rekap_pengadaan_dapur di domain F.
 */
class MedicalFeeController
{
    public function __construct(private readonly MedicalFeeReportService $laporan) {}

    public function index(Request $request): View
    {
        $komponen = $request->query('komponen', 'share_doctor');

        if (! array_key_exists($komponen, MedicalFeeReportService::KOMPONEN)) {
            $komponen = 'share_doctor';
        }

        $dari = Carbon::parse($request->query('dari', now()->startOfMonth()->toDateString()))->toDateString();
        $sampai = Carbon::parse($request->query('sampai', now()->toDateString()))->toDateString();
        $tahun = (int) $request->query('tahun', now()->year);

        // Tiga kode fee_* dilayani penyaring, bukan layar tersendiri:
        // fee_ralan (ralan), fee_visit_dokter (ranap), fee_bacaan_ekg (kode layanan).
        $jenisRawat = in_array($request->query('jenis_rawat'), ['ralan', 'ranap'], true)
            ? $request->query('jenis_rawat')
            : null;

        $kodeLayanan = trim((string) $request->query('kode_layanan', '')) ?: null;

        return view('billing::jasa-medis.index', [
            'komponen' => $komponen,
            'daftarKomponen' => MedicalFeeReportService::KOMPONEN,
            'dari' => $dari,
            'sampai' => $sampai,
            'tahun' => $tahun,
            'jenisRawat' => $jenisRawat,
            'kodeLayanan' => $kodeLayanan,
            'ringkasan' => $this->laporan->summary($dari, $sampai, $jenisRawat, $kodeLayanan),
            'perPelaksana' => $this->laporan->perPractitioner($komponen, $dari, $sampai, $jenisRawat, $kodeLayanan),
            'harian' => $this->laporan->daily($komponen, $dari, $sampai, $jenisRawat, $kodeLayanan),
            'bulanan' => $this->laporan->monthly($komponen, $tahun, $jenisRawat, $kodeLayanan),
        ]);
    }
}
