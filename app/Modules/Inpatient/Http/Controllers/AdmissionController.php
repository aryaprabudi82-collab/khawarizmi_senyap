<?php

namespace App\Modules\Inpatient\Http\Controllers;

use App\Modules\Inpatient\Models\Admission;
use App\Modules\Inpatient\Models\Bed;
use App\Modules\Inpatient\Models\DietOrder;
use App\Modules\Inpatient\Services\AdmissionService;
use App\Modules\Inpatient\Services\DietOrderService;
use App\Modules\Inpatient\Services\EncounterContext;
use App\Modules\Inpatient\Services\InpatientException;
use App\Modules\Inpatient\Services\OrganizationContext;
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
        private readonly OrganizationContext $organization,
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
            'praktisi' => $this->organization->practitioners(),
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

    public function reassignDpjp(Request $request, Admission $admisi): RedirectResponse
    {
        $data = $request->validate([
            'practitioner_id' => ['required', 'integer'],
            'reason' => ['nullable', 'string', 'max:500'],
        ], [], ['practitioner_id' => 'dokter', 'reason' => 'alasan']);

        try {
            $this->admissions->reassignDpjp($admisi, $data['practitioner_id'], $data['reason'] ?? null, $request->user()?->id);
        } catch (InpatientException $e) {
            return back()->with('galat', $e->getMessage());
        }

        return back()->with('sukses', "DPJP {$admisi->patient_name} diganti.");
    }

    /**
     * Memindahkan pasien ke bed lain di tengah rawatan.
     *
     * Riwayat penempatannya dicatat, bukan sekadar menimpa bed_id — itu
     * yang membuat biaya kamar per hari tetap benar kalau kelasnya berubah.
     */
    public function transferBed(Request $request, Admission $admisi): RedirectResponse
    {
        $data = $request->validate([
            'bed_id' => ['required', 'integer'],
            'reason' => ['nullable', 'string', 'max:255'],
        ], [], ['bed_id' => 'bed tujuan', 'reason' => 'alasan pindah']);

        $bed = Bed::query()->find($data['bed_id']);

        if ($bed === null) {
            return back()->with('galat', 'Bed tujuan tidak ditemukan.');
        }

        try {
            $this->admissions->transferBed($admisi, $bed, $data['reason'] ?? null, $request->user()?->id);
        } catch (InpatientException $e) {
            return back()->with('galat', $e->getMessage());
        }

        return back()->with('sukses', "{$admisi->patient_name} dipindahkan ke bed {$bed->bed_number}.");
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
