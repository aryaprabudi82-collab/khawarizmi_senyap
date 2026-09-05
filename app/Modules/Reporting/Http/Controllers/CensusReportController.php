<?php

namespace App\Modules\Reporting\Http\Controllers;

use App\Modules\Organization\Models\Unit;
use App\Modules\Reporting\Services\CensusReportService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\View\View;

/**
 * Sensus & kunjungan (domain J item A) — satu layar berpenyaring yang
 * menaungi 21 kode laporan Khanza, karena semuanya potongan berbeda dari
 * data yang sama. Digerbangi sensus_harian_ralan sebagai kode yang paling
 * mewakili, pola umbrella seperti ringkasan_tindakan di domain I item D.
 */
class CensusReportController
{
    public function __construct(private readonly CensusReportService $sensus) {}

    public function index(Request $request): View
    {
        $dari = Carbon::parse($request->query('dari', now()->startOfMonth()->toDateString()))->toDateString();
        $sampai = Carbon::parse($request->query('sampai', now()->toDateString()))->toDateString();
        $tahun = (int) $request->query('tahun', now()->year);

        $jenisRawat = in_array($request->query('jenis_rawat'), ['ralan', 'ranap'], true)
            ? $request->query('jenis_rawat')
            : null;

        $unitId = is_numeric($request->query('unit_id')) ? (int) $request->query('unit_id') : null;

        $kategoriPenunjang = in_array($request->query('kategori'), ['lab', 'radiologi', 'pa'], true)
            ? $request->query('kategori')
            : null;

        $asal = $request->query('asal') === 'dokter' ? 'dokter' : 'unit';

        return view('reporting::sensus.index', [
            'dari' => $dari,
            'sampai' => $sampai,
            'tahun' => $tahun,
            'jenisRawat' => $jenisRawat,
            'unitId' => $unitId,
            'kategoriPenunjang' => $kategoriPenunjang,
            'asal' => $asal,
            'unit' => Unit::query()->where('is_active', true)->orderBy('name')->get(),

            'harian' => $this->sensus->dailyCensus($dari, $sampai, $jenisRawat, $unitId),
            'perUnit' => $this->sensus->byUnit($dari, $sampai, $jenisRawat),
            'perDokter' => $this->sensus->byPractitioner($dari, $sampai, $jenisRawat, $unitId),
            'perPenjamin' => $this->sensus->byPayer($dari, $sampai, $jenisRawat, $unitId),
            'perJam' => $this->sensus->arrivalByHour($dari, $sampai, $jenisRawat, $unitId),
            'perUmur' => $this->sensus->byAgeGroup($dari, $sampai, $jenisRawat, $unitId),
            'batal' => $this->sensus->cancelled($dari, $sampai, $unitId),
            'bulanan' => $this->sensus->monthly($tahun, $jenisRawat, $unitId),

            'admisi' => $this->sensus->admissions($dari, $sampai),
            'perRuang' => $this->sensus->admissionsByRoom($dari, $sampai),
            'asalRanap' => $this->sensus->admissionOrigin($dari, $sampai, $asal),

            'penunjang' => $this->sensus->supportOrders($dari, $sampai, $kategoriPenunjang, $jenisRawat),
        ]);
    }
}
