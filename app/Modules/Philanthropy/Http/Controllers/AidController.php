<?php

namespace App\Modules\Philanthropy\Http\Controllers;

use App\Modules\Philanthropy\Models\Assessment;
use App\Modules\Philanthropy\Models\AssessmentCriterion;
use App\Modules\Philanthropy\Models\Disbursement;
use App\Modules\Philanthropy\Models\Recipient;
use App\Modules\Philanthropy\Services\AidEligibilityService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use RuntimeException;

/**
 * Layar kerja amil: penerima, asesmen kelayakan, putusan, dan penyaluran.
 *
 * Inilah bagian yang tidak ada di Khanza sama sekali — enam belas kosakata
 * tanpa instrumen yang memakainya, dan penyaluran yang tidak menyebut
 * penerimanya.
 */
class AidController
{
    public function __construct(private readonly AidEligibilityService $bantuan) {}

    public function index(Request $request): View
    {
        $dari = $request->query('dari', now()->startOfMonth()->toDateString());
        $sampai = $request->query('sampai', now()->toDateString());

        return view('philanthropy::bantuan.index', [
            'penerima' => Recipient::query()->with('asnaf')
                ->where('is_active', true)->orderBy('name')->limit(100)->get(),

            'asesmen' => Assessment::query()->with(['recipient', 'answers'])
                ->latest('assessed_on')->latest('id')->limit(50)->get(),

            'penyaluran' => Disbursement::query()->with(['recipient', 'assessment'])
                ->latest('disbursed_on')->latest('id')->limit(50)->get(),

            'asnaf' => AssessmentCriterion::query()
                ->where('category', AssessmentCriterion::KATEGORI_ASNAF)
                ->where('is_active', true)->orderBy('position')->get(),

            'kriteria' => AssessmentCriterion::query()->where('is_active', true)
                ->orderBy('category')->orderBy('position')->get()->groupBy('category'),

            'kategoriKosong' => AssessmentCriterion::kategoriKosong(),

            'rekap' => $this->bantuan->rekapSumber($dari, $sampai),
            'berulang' => $this->bantuan->penerimaBerulang($dari, $sampai),
            'periode' => ['dari' => $dari, 'sampai' => $sampai],
        ]);
    }

    public function storeRecipient(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:150'],
            'id_number' => ['nullable', 'string', 'max:30'],
            'sex' => ['nullable', 'in:L,P'],
            'birth_date' => ['nullable', 'date'],
            'address' => ['nullable', 'string', 'max:250'],
            'phone' => ['nullable', 'string', 'max:30'],
            'patient_mrn' => ['nullable', 'string', 'max:30'],
        ], [], ['name' => 'nama', 'id_number' => 'NIK', 'birth_date' => 'tanggal lahir']);

        $penerima = $this->bantuan->registerRecipient($data);

        return back()->with('sukses', 'Penerima "'.$penerima->name.'" terdaftar dengan nomor '
            .$penerima->recipient_number.'.');
    }

    public function setAsnaf(Request $request, Recipient $penerima): RedirectResponse
    {
        $data = $request->validate([
            'asnaf_criteria_id' => ['required', 'integer', 'exists:App\Modules\Philanthropy\Models\AssessmentCriterion,id'],
        ], [], ['asnaf_criteria_id' => 'golongan asnaf']);

        $asnaf = AssessmentCriterion::query()->findOrFail($data['asnaf_criteria_id']);

        return $this->jalankan(fn () => $this->bantuan->tetapkanAsnaf($penerima, $asnaf),
            'Golongan asnaf "'.$asnaf->name.'" ditetapkan untuk '.$penerima->name.'.');
    }

    public function storeAssessment(Request $request, Recipient $penerima): RedirectResponse
    {
        $data = $request->validate([
            'assessed_on' => ['required', 'date'],
            'surveyor_name' => ['required', 'string', 'max:150'],
            'note' => ['nullable', 'string', 'max:2000'],
        ], [], ['assessed_on' => 'tanggal survei', 'surveyor_name' => 'nama surveyor']);

        $asesmen = $this->bantuan->openAssessment($penerima, $data);

        return redirect()->route('philanthropy.bantuan.asesmen', $asesmen)
            ->with('sukses', 'Asesmen '.$asesmen->assessment_number.' dibuka.');
    }

    public function showAssessment(Assessment $asesmen): View
    {
        $asesmen->load(['recipient.asnaf', 'answers.criterion', 'disbursements']);

        return view('philanthropy::bantuan.asesmen', [
            'asesmen' => $asesmen,
            'jawaban' => $asesmen->answers->keyBy('category'),
            'kriteria' => AssessmentCriterion::query()->where('is_active', true)
                ->orderBy('position')->orderBy('id')->get()->groupBy('category'),
            'belumDijawab' => $asesmen->kategoriBelumDijawab(),
            'totalBobot' => $asesmen->totalBobot(),
            'kategoriKosong' => AssessmentCriterion::kategoriKosong(),
        ]);
    }

    public function answer(Request $request, Assessment $asesmen): RedirectResponse
    {
        $data = $request->validate([
            'category' => ['required', Rule::in(AssessmentCriterion::KATEGORI)],

            // Boleh kosong: "ditanyakan tapi tidak terjawab" adalah keadaan
            // yang sah dan berbeda dari kategori yang tidak pernah disentuh.
            'criteria_id' => ['nullable', 'integer', 'exists:App\Modules\Philanthropy\Models\AssessmentCriterion,id'],
            'note' => ['nullable', 'string', 'max:500'],
        ], [], ['category' => 'kategori', 'criteria_id' => 'pilihan']);

        $kriteria = $data['criteria_id'] ?? null
            ? AssessmentCriterion::query()->findOrFail($data['criteria_id'])
            : null;

        return $this->jalankan(
            fn () => $this->bantuan->answer($asesmen, $data['category'], $kriteria, $data['note'] ?? null),
            'Jawaban kategori '.AssessmentCriterion::LABEL_KATEGORI[$data['category']].' tersimpan.'
        );
    }

    public function decide(Request $request, Assessment $asesmen): RedirectResponse
    {
        $data = $request->validate([
            'decision' => ['required', 'in:layak,tidak-layak'],

            /*
             * Alasan wajib pada KEDUA arah, bukan hanya penolakan: bantuan
             * yang diberikan tanpa alasan tercatat sama sulitnya
             * dipertanggungjawabkan dengan penolakan tanpa alasan, dan
             * keduanya memakai uang titipan orang.
             */
            'decision_reason' => ['required', 'string', 'max:2000'],
            'decided_by_name' => ['required', 'string', 'max:150'],
            'recommended_amount' => ['nullable', 'numeric', 'min:0'],
        ], [], [
            'decision' => 'putusan', 'decision_reason' => 'alasan',
            'decided_by_name' => 'nama pemutus', 'recommended_amount' => 'usulan nominal',
        ]);

        return $this->jalankan(fn () => $this->bantuan->decide(
            $asesmen,
            $data['decision'],
            $data['decision_reason'],
            $data['decided_by_name'],
            isset($data['recommended_amount']) ? (float) $data['recommended_amount'] : null,
        ), 'Putusan "'.$data['decision'].'" tercatat atas asesmen '.$asesmen->assessment_number.'.');
    }

    public function disburse(Request $request, Assessment $asesmen): RedirectResponse
    {
        $data = $request->validate([
            'disbursed_on' => ['required', 'date'],
            'fund_source' => ['required', Rule::in(Disbursement::SUMBER)],
            'amount' => ['required', 'numeric', 'min:0.01'],
            'purpose' => ['required', 'string', 'max:200'],
            'note' => ['nullable', 'string', 'max:500'],
            'disbursed_by_name' => ['nullable', 'string', 'max:150'],
        ], [], [
            'disbursed_on' => 'tanggal salur', 'fund_source' => 'sumber dana',
            'amount' => 'jumlah', 'purpose' => 'peruntukan',
        ]);

        return $this->jalankan(function () use ($asesmen, $data) {
            $salur = $this->bantuan->disburse($asesmen, $data + [
                'disbursed_by' => request()->user()?->getKey(),
            ]);

            return $salur;
        }, 'Penyaluran tercatat.');
    }

    /**
     * Aturan domain dilanggar tampil sebagai pesan, bukan halaman error:
     * yang membacanya petugas di meja, dan halaman 500 tidak memberitahu
     * apa pun tentang apa yang harus diperbaiki.
     */
    private function jalankan(callable $aksi, string $pesan): RedirectResponse
    {
        try {
            $aksi();
        } catch (RuntimeException $e) {
            return back()->withInput()->with('galat', $e->getMessage());
        }

        return back()->with('sukses', $pesan);
    }
}
