<?php

namespace App\Modules\Quality\Http\Controllers;

use App\Modules\Quality\Models\InfectionEvent;
use App\Modules\Quality\Services\HaisSurveillanceService;
use App\Modules\Quality\Services\QualityException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Surveilans HAIs (domain J item E): pencatatan kejadian, pencatatan
 * penyebut, dan laporannya dalam satu layar.
 *
 * Pencatatan dan laporan disatukan karena keduanya pekerjaan orang yang
 * sama (perawat/tim PPI) pada waktu yang sama, dan karena laporan yang
 * memperlihatkan penyebut mana yang belum dicatat justru paling berguna
 * tepat di sebelah tempat mengisinya.
 */
class HaisController
{
    public function __construct(private readonly HaisSurveillanceService $hais) {}

    public function index(Request $request): View
    {
        $dari = $request->query('dari', now()->startOfMonth()->toDateString());
        $sampai = $request->query('sampai', now()->toDateString());
        $unit = $request->query('unit') ?: null;
        $jenis = $request->query('jenis') ?: null;

        if ($jenis !== null && ! isset(InfectionEvent::JENIS[$jenis])) {
            $jenis = null;
        }

        return view('quality::hais.index', [
            'dari' => $dari,
            'sampai' => $sampai,
            'unit' => $unit,
            'jenis' => $jenis,

            'daftarJenis' => InfectionEvent::JENIS,
            'daftarAlat' => InfectionEvent::ALAT,

            'perJenis' => $this->hais->ratesByType($dari, $sampai, $unit),
            'perBangsal' => $this->hais->ratesByUnit($dari, $sampai, $jenis),
            'harian' => $this->hais->dailyEvents($dari, $sampai, $unit),
            'bulanan' => $this->hais->monthlyEvents($dari, $sampai, $unit),
            'kejadian' => $this->hais->events($dari, $sampai, $unit),

            'hariTanpaPenyebut' => $this->hais->missingDenominatorDays($dari, $sampai),
            'bangsalTanpaPenyebut' => $this->hais->unitsWithoutDenominator($dari, $sampai),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'patient_id' => ['required', 'integer'],
            'patient_mrn' => ['required', 'string', 'max:30'],
            'patient_name' => ['required', 'string', 'max:120'],
            'unit_name' => ['required', 'string', 'max:120'],
            'infection_type' => ['required', 'string'],
            'device' => ['nullable', 'string'],
            'onset_on' => ['required', 'date'],
            'days_after_admission' => ['nullable', 'integer', 'min:0'],
            'clinical_criteria' => ['required', 'string'],
            'culture_result' => ['nullable', 'string', 'max:200'],
        ]);

        $data['reported_by'] = $request->user()?->id;
        $data['reported_by_name'] = $request->user()?->name;

        try {
            $this->hais->recordEvent($data);
        } catch (QualityException $e) {
            return back()->withInput()->withErrors(['infection_type' => $e->getMessage()]);
        }

        return back()->with('status', 'Kejadian infeksi tercatat.');
    }

    public function storeDenominator(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'unit_name' => ['required', 'string', 'max:120'],
            'counted_on' => ['required', 'date'],
            'patient_days' => ['nullable', 'integer', 'min:0'],
            'ventilator_days' => ['nullable', 'integer', 'min:0'],
            'central_line_days' => ['nullable', 'integer', 'min:0'],
            'urinary_catheter_days' => ['nullable', 'integer', 'min:0'],
            'peripheral_line_days' => ['nullable', 'integer', 'min:0'],
        ]);

        $this->hais->recordDenominator(
            $data['unit_name'],
            $data['counted_on'],
            $data,
            $request->user()?->id,
        );

        return back()->with('status', 'Penyebut hari-alat tersimpan.');
    }
}
