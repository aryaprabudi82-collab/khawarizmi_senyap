<?php

namespace App\Modules\Quality\Http\Controllers;

use App\Modules\Quality\Models\IcraActivityType;
use App\Modules\Quality\Models\IcraArea;
use App\Modules\Quality\Models\IcraAssessment;
use App\Modules\Quality\Models\IcraClassRequirement;
use App\Modules\Quality\Models\IcraControlMeasure;
use App\Modules\Quality\Models\IcraMatrixCell;
use App\Modules\Quality\Models\IcraPrecautionClass;
use App\Modules\Quality\Models\IcraRiskGroup;
use App\Modules\Quality\Services\IcraService;
use App\Modules\Quality\Services\OrganizationContext;
use App\Modules\Quality\Services\QualityException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class IcraController
{
    public function __construct(
        private readonly IcraService $icra,
        private readonly OrganizationContext $organization,
    ) {}

    public function index(): View
    {
        return view('quality::icra.index', [
            'kajian' => IcraAssessment::query()->latest('assessed_at')->limit(50)->get(),
            'unit' => $this->organization->units(),
            'aktivitas' => IcraActivityType::query()->where('is_active', true)->orderBy('position')->get(),
            'area' => IcraArea::query()->with('riskGroup')->where('is_active', true)->orderBy('name')->get(),
            'kelas' => IcraPrecautionClass::query()->orderBy('position')->get(),
            'matriks' => IcraMatrixCell::query()->with(['activityType', 'riskGroup', 'minClass', 'maxClass'])->get(),
            'kelompok' => IcraRiskGroup::query()->orderBy('position')->get(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'project_name' => ['required', 'string', 'max:200'],
            'project_type' => ['required', 'string', 'max:50'],
            'location' => ['required', 'string', 'max:150'],
            'unit_id' => ['nullable', 'integer'],
            'infection_risk_level' => ['required', 'in:rendah,sedang,tinggi,sangat-tinggi'],
            'fire_risk_level' => ['required', 'in:rendah,sedang,tinggi,sangat-tinggi'],
            'safety_risk_level' => ['required', 'in:rendah,sedang,tinggi,sangat-tinggi'],
            'utility_risk_level' => ['required', 'in:rendah,sedang,tinggi,sangat-tinggi'],
            'required_precautions' => ['nullable', 'string', 'max:2000'],
            'control_measures' => ['nullable', 'string', 'max:2000'],
            'valid_until' => ['nullable', 'date'],

            'activity_type_id' => ['required', 'integer', 'exists:quality.icra_activity_types,id'],
            'area_id' => ['required', 'integer', 'exists:quality.icra_areas,id'],

            // Hanya dipakai bila sel matriksnya memberi rentang.
            'chosen_class_id' => ['nullable', 'integer', 'exists:quality.icra_precaution_classes,id'],
            'class_decided_by' => ['nullable', 'string', 'max:150'],
            'class_decision_reason' => ['nullable', 'string', 'max:2000'],
        ], [], [
            'project_name' => 'nama proyek', 'project_type' => 'jenis aktivitas', 'location' => 'lokasi',
            'infection_risk_level' => 'risiko infeksi', 'fire_risk_level' => 'risiko kebakaran',
            'safety_risk_level' => 'risiko keselamatan', 'utility_risk_level' => 'risiko utilitas',
            'activity_type_id' => 'tipe aktivitas proyek', 'area_id' => 'area terdampak',
            'chosen_class_id' => 'kelas pilihan', 'class_decided_by' => 'pemutus kelas',
            'class_decision_reason' => 'alasan pemilihan kelas',
        ]);

        $aktivitas = IcraActivityType::query()->findOrFail($data['activity_type_id']);
        $area = IcraArea::query()->findOrFail($data['area_id']);
        $pilihan = isset($data['chosen_class_id'])
            ? IcraPrecautionClass::query()->find($data['chosen_class_id'])
            : null;

        $pemutus = $data['class_decided_by'] ?? null;
        $alasan = $data['class_decision_reason'] ?? null;
        unset($data['chosen_class_id'], $data['class_decided_by'], $data['class_decision_reason']);

        try {
            // Kelas pencegahan sengaja TIDAK dikirim: service mengambilnya
            // dari matriks.
            $kajian = $this->icra->assess($data, $request->user()->id, $aktivitas, $area, $pilihan, $pemutus, $alasan);
        } catch (QualityException $e) {
            return back()->withInput()->with('galat', $e->getMessage());
        }

        return back()->with('sukses', "Kajian {$kajian->assessment_number} tersimpan, kelas pencegahan {$kajian->risk_class}.");
    }

    public function complete(IcraAssessment $kajian): RedirectResponse
    {
        try {
            $this->icra->complete($kajian);
        } catch (QualityException $e) {
            return back()->with('galat', $e->getMessage());
        }

        return back()->with('sukses', "Kajian {$kajian->assessment_number} ditandai selesai.");
    }

    public function cancel(IcraAssessment $kajian): RedirectResponse
    {
        try {
            $this->icra->cancel($kajian);
        } catch (QualityException $e) {
            return back()->with('galat', $e->getMessage());
        }

        return back()->with('sukses', "Kajian {$kajian->assessment_number} dibatalkan.");
    }

    // ------------------------------------------------------------ master

    public function master(): View
    {
        return view('quality::icra.master', [
            'aktivitas' => IcraActivityType::query()->orderBy('position')->get(),
            'kelompok' => IcraRiskGroup::query()->orderBy('position')->get(),
            'kelas' => IcraPrecautionClass::query()->orderBy('position')->get(),
            'matriks' => IcraMatrixCell::query()->with(['activityType', 'riskGroup', 'minClass', 'maxClass'])->get(),
            'area' => IcraArea::query()->with('riskGroup')->orderBy('code')->get(),
            'tindakan' => IcraControlMeasure::query()->with('precautionClass')->orderBy('code')->get(),
            'persyaratan' => IcraClassRequirement::query()->with('precautionClass')->orderBy('precaution_class_id')->orderBy('position')->get(),
            'unit' => $this->organization->units(),
        ]);
    }

    public function storeArea(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'max:20', 'unique:quality.icra_areas,code'],
            'name' => ['required', 'string', 'max:150'],
            'risk_group_id' => ['required', 'integer', 'exists:quality.icra_risk_groups,id'],
            'unit_id' => ['nullable', 'integer'],
            'note' => ['nullable', 'string', 'max:1000'],
        ], [], ['code' => 'kode area', 'name' => 'nama area', 'risk_group_id' => 'kelompok risiko']);

        IcraArea::query()->create($data + ['is_active' => true]);

        return back()->with('sukses', 'Area "'.$data['name'].'" ditambahkan.');
    }

    public function storeControlMeasure(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'max:20', 'unique:quality.icra_control_measures,code'],
            'name' => ['required', 'string', 'max:200'],
            'precaution_class_id' => ['nullable', 'integer', 'exists:quality.icra_precaution_classes,id'],
        ], [], ['code' => 'kode', 'name' => 'tindakan pengendalian', 'precaution_class_id' => 'kelas terendah']);

        IcraControlMeasure::query()->create($data + ['is_active' => true]);

        return back()->with('sukses', 'Tindakan pengendalian ditambahkan.');
    }

    public function storeRequirement(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'precaution_class_id' => ['required', 'integer', 'exists:quality.icra_precaution_classes,id'],
            'requirement' => ['required', 'string', 'max:2000'],
        ], [], ['precaution_class_id' => 'kelas pencegahan', 'requirement' => 'persyaratan']);

        $urut = (int) IcraClassRequirement::query()
            ->where('precaution_class_id', $data['precaution_class_id'])
            ->max('position');

        IcraClassRequirement::query()->create($data + ['position' => $urut + 1, 'is_active' => true]);

        return back()->with('sukses', 'Persyaratan ditambahkan.');
    }

    public function updateMatrix(Request $request, IcraMatrixCell $sel): RedirectResponse
    {
        $data = $request->validate([
            'min_class_id' => ['required', 'integer', 'exists:quality.icra_precaution_classes,id'],
            'max_class_id' => ['nullable', 'integer', 'exists:quality.icra_precaution_classes,id'],
        ], [], ['min_class_id' => 'kelas minimum', 'max_class_id' => 'kelas maksimum']);

        /*
         * Matriksnya DATA: IPCN harus bisa mencocokkannya dengan acuan
         * mereka sendiri tanpa menunggu migrasi. Isian awalnya mengikuti
         * matriks yang lazim diterbitkan dan MEMANG PERLU DIPERIKSA.
         */
        $sel->update([
            'min_class_id' => $data['min_class_id'],
            'max_class_id' => $data['max_class_id'] ?? null,
        ]);

        return back()->with('sukses', 'Sel matriks diperbarui.');
    }
}
