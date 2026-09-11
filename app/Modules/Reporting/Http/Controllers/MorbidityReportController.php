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

        /*
         * KARTU INDEKS PENYAKIT DIGERBANGI SENDIRI, dan itu keputusan yang
         * disengaja. Seluruh isi layar ini agregat — berapa banyak kasus,
         * per penularan, per penjamin — dan tidak menyebut satu pun nama.
         * KIP satu-satunya yang menyebut SIAPA: nomor rekam medis, nama,
         * tanggal lahir. Menumpangkannya pada gerbang yang sama berarti
         * setiap orang yang boleh melihat statistik penyakit otomatis
         * boleh menarik daftar nama pengidapnya, dan itu pertanyaan yang
         * berbeda — yang pertama untuk perencanaan, yang kedua untuk
         * penelusuran kasus.
         *
         * Diperiksa imperatif, bukan middleware, karena yang digerbangi
         * satu BAGIAN layar, bukan layarnya. Tercatat di
         * CodeDispositionTest::$diperiksaImperatif.
         */
        $bolehKip = $request->user()?->can('kip_pasien_ralan') ?? false;

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

            'bolehKip' => $bolehKip,
            'kip' => $bolehKip && $kodeObat
                ? $this->morbiditas->patientsForDiagnosis($kodeObat, $dari, $sampai, $jenisRawat)
                : collect(),
        ]);
    }
}
