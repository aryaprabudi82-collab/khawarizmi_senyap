<?php

namespace App\Modules\Inpatient\Http\Controllers;

use App\Modules\Inpatient\Models\Admission;
use App\Modules\Inpatient\Models\Bed;
use App\Modules\Inpatient\Models\DietOrder;
use App\Modules\Inpatient\Services\AdmissionService;
use App\Modules\Inpatient\Services\DietOrderService;
use App\Modules\Inpatient\Services\EncounterContext;
use App\Modules\Inpatient\Services\InpatientException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class AdmissionController
{
    public function __construct(
        private readonly AdmissionService $admissions,
        private readonly DietOrderService $dietOrders,
        private readonly EncounterContext $encounter,
    ) {}

    public function index(Request $request): View
    {
        // Rute tunggal ini dibagi dua pemegang izin (lihat catatan di routes),
        // jadi diperiksa imperatif, bukan middleware can: biasa.
        abort_unless(
            $request->user()?->can('tindakan_ranap') || $request->user()?->can('diet_pasien'),
            403
        );

        return view('inpatient::admisi.index', [
            'menunggu' => $this->encounter->awaitingAdmission(),
            'dirawat' => Admission::query()->with(['bed.room', 'activeDietOrder'])->where('status', Admission::STATUS_DIRAWAT)->orderBy('admitted_at')->get(),
            'bedTersedia' => Bed::query()->with('room')->where('status', Bed::STATUS_TERSEDIA)->get(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'registration_id' => ['required', 'integer'],
            'bed_id' => ['required', 'integer', Rule::exists(Bed::class, 'id')],
        ], [], ['registration_id' => 'registrasi', 'bed_id' => 'bed']);

        $bed = Bed::query()->findOrFail($data['bed_id']);

        try {
            $admisi = $this->admissions->admit($data['registration_id'], $bed, $request->user()?->id);
        } catch (InpatientException $e) {
            return back()->with('galat', $e->getMessage());
        }

        return back()->with('sukses', "{$admisi->patient_name} diadmisi ke bed {$bed->bed_number}, nomor admisi {$admisi->admission_number}.");
    }

    public function discharge(Request $request, Admission $admisi): RedirectResponse
    {
        $data = $request->validate([
            'discharge_status' => ['required', Rule::in(Admission::DISCHARGE_STATUSES)],
            'note' => ['nullable', 'string', 'max:1000'],
        ], [], ['discharge_status' => 'status pulang']);

        try {
            $this->admissions->discharge($admisi, $data['discharge_status'], $data['note'] ?? null, $request->user()?->id);
        } catch (InpatientException $e) {
            return back()->with('galat', $e->getMessage());
        }

        return back()->with('sukses', "{$admisi->patient_name} dipulangkan. Bed {$admisi->bed->bed_number} menunggu dibersihkan.");
    }

    public function storeDiet(Request $request, Admission $admisi): RedirectResponse
    {
        $data = $request->validate([
            'diet_type' => ['required', Rule::in(DietOrder::TYPES)],
            'note' => ['nullable', 'string', 'max:500'],
            'start_date' => ['required', 'date'],
        ], [], ['diet_type' => 'jenis diet', 'start_date' => 'tanggal mulai']);

        try {
            $this->dietOrders->order($admisi, $data, $request->user());
        } catch (InpatientException $e) {
            return back()->with('galat', $e->getMessage());
        }

        return back()->with('sukses', "Order diet {$admisi->patient_name} tercatat.");
    }

    public function stopDiet(DietOrder $diet): RedirectResponse
    {
        try {
            $this->dietOrders->stop($diet);
        } catch (InpatientException $e) {
            return back()->with('galat', $e->getMessage());
        }

        return back()->with('sukses', 'Order diet dihentikan.');
    }
}
