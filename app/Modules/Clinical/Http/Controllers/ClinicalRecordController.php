<?php

namespace App\Modules\Clinical\Http\Controllers;

use App\Modules\Clinical\Models\Assessment;
use App\Modules\Clinical\Models\Diagnosis;
use App\Modules\Clinical\Models\DiagnosisCode;
use App\Modules\Clinical\Models\Observation;
use App\Modules\Clinical\Services\ClinicalException;
use App\Modules\Clinical\Services\ClinicalRecordService;
use App\Modules\Clinical\Services\RegistrationContext;
use App\Modules\Organization\Services\OrganizationDirectory;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ClinicalRecordController
{
    public function __construct(
        private readonly ClinicalRecordService $records,
        private readonly RegistrationContext $registrations,
        private readonly OrganizationDirectory $organization,
    ) {}

    /** Daftar pasien yang menunggu diperiksa. */
    public function index(Request $request): View
    {
        $tanggal = CarbonImmutable::parse($request->query('tanggal', now()->toDateString()))->startOfDay();

        $menunggu = $this->registrations->waitingOn(
            date: $tanggal->toDateString(),
            unitId: $request->integer('unit_id') ?: null,
            practitionerId: $request->integer('praktisi_id') ?: null,
        );

        // Menandai kunjungan yang asesmennya sudah dimulai, supaya dokter tahu
        // mana yang tinggal dilanjutkan.
        $sudahDinilai = Assessment::query()
            ->whereIn('registration_id', $menunggu->pluck('id'))
            ->pluck('status', 'registration_id');

        return view('clinical::records.index', [
            'tanggal' => $tanggal,
            'menunggu' => $menunggu,
            'sudahDinilai' => $sudahDinilai,
            'units' => $this->organization->activeUnits(),
            'praktisi' => $this->organization->practitionersServingOn($tanggal),
            'unitId' => $request->integer('unit_id') ?: null,
            'praktisiId' => $request->integer('praktisi_id') ?: null,
        ]);
    }

    /** Layar pemeriksaan untuk satu kunjungan. */
    public function edit(Request $request, int $registrasi): View|RedirectResponse
    {
        $kind = $request->query('jenis', Assessment::KIND_SOAP);

        try {
            $assessment = $this->records->openAssessment($registrasi, $kind, $request->user());
        } catch (ClinicalException $e) {
            return redirect()->route('rme.index')->with('galat', $e->getMessage());
        }

        return view('clinical::records.edit', [
            'assessment' => $assessment,
            'kunjungan' => $this->registrations->find($registrasi),
            'observasi' => $this->records->latestObservations($registrasi),
            'katalogObservasi' => Observation::CATALOG,
            'diagnosis' => $this->records->diagnosesFor($registrasi),
            'alergi' => $this->records->allergiesFor($assessment->patient_id),
            'riwayat' => $this->registrations->historyFor($assessment->patient_id, 10),
            'revisi' => $assessment->revisions()->get(),
            'skrining' => $this->records->screeningFor($registrasi),
        ]);
    }

    public function storeScreening(Request $request, int $registrasi): RedirectResponse
    {
        $data = $request->validate([
            'fall_risk_level' => ['required', 'in:rendah,sedang,tinggi'],
            'pain_score' => ['required', 'integer', 'min:0', 'max:10'],
            'nutrition_at_risk' => ['nullable', 'boolean'],
            'infectious_symptom' => ['nullable', 'boolean'],
            'special_needs' => ['nullable', 'string', 'max:500'],
        ], [], [
            'fall_risk_level' => 'risiko jatuh', 'pain_score' => 'skala nyeri',
            'nutrition_at_risk' => 'risiko gizi', 'infectious_symptom' => 'gejala menular',
        ]);

        $data['nutrition_at_risk'] = $request->boolean('nutrition_at_risk');
        $data['infectious_symptom'] = $request->boolean('infectious_symptom');

        try {
            $this->records->recordScreening($registrasi, $data, $request->user());
        } catch (ClinicalException $e) {
            return back()->with('galat', $e->getMessage());
        }

        return back()->with('sukses', 'Skrining awal tercatat.');
    }

    public function update(Request $request, Assessment $assessment): RedirectResponse
    {
        $data = $request->validate([
            'chief_complaint' => ['nullable', 'string', 'max:2000'],
            'subjective' => ['nullable', 'string', 'max:5000'],
            'objective' => ['nullable', 'string', 'max:5000'],
            'assessment' => ['nullable', 'string', 'max:5000'],
            'plan' => ['nullable', 'string', 'max:5000'],
            'alasan_ralat' => ['nullable', 'string', 'max:255'],
            'vital' => ['nullable', 'array'],
            'vital.*' => ['nullable', 'numeric'],
        ], [], [
            'chief_complaint' => 'keluhan utama',
            'alasan_ralat' => 'alasan ralat',
        ]);

        try {
            $this->records->saveAssessment(
                assessment: $assessment,
                content: $data,
                actor: $request->user(),
                reason: $data['alasan_ralat'] ?? null,
            );

            $tersimpan = $this->records->recordObservations(
                $assessment->refresh(),
                $data['vital'] ?? [],
                $request->user(),
            );
        } catch (ClinicalException $e) {
            return back()->withInput()->with('galat', $e->getMessage());
        }

        $pesan = 'Catatan pemeriksaan tersimpan.';

        if ($tersimpan > 0) {
            $pesan .= " {$tersimpan} pengukuran tanda vital dicatat.";
        }

        return back()->with('sukses', $pesan);
    }

    public function finalize(Request $request, Assessment $assessment): RedirectResponse
    {
        try {
            $this->records->finalizeAssessment($assessment, $request->user());
        } catch (ClinicalException $e) {
            return back()->with('galat', $e->getMessage());
        }

        return back()->with(
            'sukses',
            'Asesmen difinalkan. Perubahan berikutnya akan tercatat sebagai ralat berversi.'
        );
    }

    public function storeDiagnosis(Request $request, Assessment $assessment): RedirectResponse
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'max:12'],
            'rank' => ['required', 'in:utama,sekunder,komplikasi'],
            'certainty' => ['required', 'in:suspek,kerja,definitif'],
            'note' => ['nullable', 'string', 'max:500'],
        ], [], [
            'code' => 'kode diagnosis',
            'rank' => 'peringkat diagnosis',
            'certainty' => 'tingkat kepastian',
        ]);

        $kode = DiagnosisCode::query()->find($data['code']);

        if ($kode === null) {
            return back()->with('galat', "Kode diagnosis {$data['code']} tidak ada di kamus ICD-10.");
        }

        try {
            $this->records->addDiagnosis(
                assessment: $assessment,
                code: $kode->code,
                display: $kode->display_id ?: $kode->display,
                rank: $data['rank'],
                certainty: $data['certainty'],
                note: $data['note'] ?? null,
                actor: $request->user(),
            );
        } catch (ClinicalException $e) {
            return back()->with('galat', $e->getMessage());
        }

        return back()->with('sukses', "Diagnosis {$kode->code} ditambahkan.");
    }

    public function destroyDiagnosis(Diagnosis $diagnosis): RedirectResponse
    {
        // Soft delete: data klinis tidak pernah dihapus keras, sesuai
        // Permenkes 24/2022.
        $diagnosis->delete();

        return back()->with('sukses', "Diagnosis {$diagnosis->code} dihapus dari kunjungan ini.");
    }

    public function storeAllergy(Request $request, Assessment $assessment): RedirectResponse
    {
        $data = $request->validate([
            'substance' => ['required', 'string', 'max:150'],
            'category' => ['required', 'in:obat,makanan,lingkungan,lainnya'],
            'severity' => ['required', 'in:ringan,sedang,berat'],
            'reaction' => ['nullable', 'string', 'max:255'],
        ], [], [
            'substance' => 'zat penyebab',
            'category' => 'kategori',
            'severity' => 'derajat',
            'reaction' => 'reaksi',
        ]);

        try {
            $this->records->recordAllergy(
                patientId: $assessment->patient_id,
                substance: $data['substance'],
                attributes: $data + [
                    'practitioner_id' => $assessment->practitioner_id,
                    'practitioner_name' => $assessment->practitioner_name,
                ],
                registrationId: $assessment->registration_id,
                actor: $request->user(),
            );
        } catch (ClinicalException $e) {
            return back()->with('galat', $e->getMessage());
        }

        return back()->with('sukses', "Alergi terhadap {$data['substance']} dicatat.");
    }

    /** Pencarian kode diagnosis untuk autocomplete di formulir. */
    public function searchDiagnosisCodes(Request $request)
    {
        return DiagnosisCode::query()
            ->search((string) $request->query('q', ''))
            ->orderBy('code')
            ->limit(20)
            ->get(['code', 'display', 'display_id'])
            ->map(fn (DiagnosisCode $k) => [
                'code' => $k->code,
                'label' => $k->label(),
            ]);
    }
}
