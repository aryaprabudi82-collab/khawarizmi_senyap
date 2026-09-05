<?php

namespace App\Modules\Reporting\Http\Controllers;

use App\Modules\Reporting\Services\MorbidityReportService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\View\View;

/**
 * Morbiditas & surveilans penyakit (domain J item B) — satu layar
 * berpenyaring yang menaungi 15 kode efektif. Digerbangi penyakit_ralan
 * sebagai kode yang paling mewakili.
 */
class MorbidityReportController
{
    public function __construct(private readonly MorbidityReportService $morbiditas) {}

    public function index(Request $request): View
    {
        $dari = Carbon::parse($request->query('dari', now()->startOfMonth()->toDateString()))->toDateString();
        $sampai = Carbon::parse($request->query('sampai', now()->toDateString()))->toDateString();

        $jenisRawat = in_array($request->query('jenis_rawat'), ['ralan', 'ranap'], true)
            ? $request->query('jenis_rawat')
            : null;

        $penularan = in_array($request->query('penularan'), ['menular', 'tidak-menular', 'tidak-diketahui'], true)
            ? $request->query('penularan')
            : 'menular';

        $kelompok = $this->morbiditas->surveillanceGroups();
        $kelompokDipilih = $request->query('kelompok') ?: ($kelompok->first()->group ?? null);

        $kodeObat = trim((string) $request->query('kode', '')) ?: null;

        return view('reporting::morbiditas.index', [
            'dari' => $dari,
            'sampai' => $sampai,
            'jenisRawat' => $jenisRawat,
            'penularan' => $penularan,
            'kelompok' => $kelompok,
            'kelompokDipilih' => $kelompokDipilih,
            'kodeObat' => $kodeObat,

            'frekuensi' => $this->morbiditas->frequency($dari, $sampai, $jenisRawat),
            'perPenularan' => $this->morbiditas->byTransmission($dari, $sampai, $jenisRawat),
            'rincianPenularan' => $this->morbiditas->detailByTransmission($penularan, $dari, $sampai, $jenisRawat),
            'perSurveilans' => $kelompokDipilih
                ? $this->morbiditas->bySurveillanceGroup($kelompokDipilih, $dari, $sampai, $jenisRawat)
                : collect(),
            'perPenjamin' => $this->morbiditas->byPayer($dari, $sampai, $jenisRawat),
            'obat' => $kodeObat ? $this->morbiditas->drugsForDisease($kodeObat, $dari, $sampai) : collect(),
        ]);
    }
}
