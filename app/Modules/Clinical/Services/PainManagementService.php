<?php

namespace App\Modules\Clinical\Services;

use App\Modules\Clinical\Models\PainAssessment;
use App\Modules\Clinical\Models\PainIntervention;
use App\Modules\Platform\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Pengelolaan nyeri (domain M item O).
 *
 * LIMA ATURAN.
 *
 * 1. LINGKARANNYA DITUTUP. intervensi_nyeri_farmakologi dan
 *    _nonfarmakologi punya kode izin di Khanza tapi tidak punya tabel
 *    sama sekali, jadi penilaian dan penilaian ulang ada sementara apa
 *    yang dikerjakan di antaranya tidak. Di sini intervensi menunjuk
 *    penilaian yang diresponsnya, dan penilaian ulang menunjuk
 *    intervensi yang dievaluasinya.
 *
 * 2. ALAT UKUR IKUT DICATAT. Skor 3 dari FLACC pada bayi bukan skor 3
 *    dari NRS pada dewasa, dan CPOT bahkan berhenti di 8. Menyimpan
 *    angka tanpa alatnya membuat tren nyeri seorang pasien yang alat
 *    ukurnya berganti tampak berubah tanpa ada yang berubah pada
 *    pasiennya.
 *
 * 3. NOL ADALAH HASIL PENILAIAN YANG SAH. "Tidak nyeri" bukan
 *    "penilaian belum diisi", dan aturan yang sama sudah berlaku pada
 *    skor formulir sejak item A.
 *
 * 4. YANG MEREDAKAN NYERI ADALAH DAFTAR. Enum satu pilihan Khanza
 *    memaksa pasien yang nyerinya reda dengan obat DAN perubahan posisi
 *    memilih salah satunya.
 *
 * 5. PENILAIAN ULANG DITAGIH, TIDAK DIPAKSAKAN. Intervensi yang lewat
 *    tenggat tanpa evaluasi bisa disebutkan; melarang apa pun karena
 *    evaluasinya belum ada hanya akan membuat intervensinya tidak
 *    dicatat sama sekali.
 */
class PainManagementService
{
    private const REGISTRASI = 'encounter.v_registration_summary';

    /**
     * Mencatat penilaian nyeri.
     *
     * @throws ClinicalException
     */
    public function assess(int $registrationId, array $data, ?User $actor = null): PainAssessment
    {
        $kunjungan = DB::table(self::REGISTRASI)->where('id', $registrationId)->first()
            ?? throw new ClinicalException('Kunjungan tidak ditemukan atau sudah dibatalkan.');

        $alat = $data['scale_type'] ?? '';

        if (! array_key_exists($alat, PainAssessment::SKALA)) {
            throw new ClinicalException(
                "Alat ukur nyeri '{$alat}' tidak dikenali. Pilihannya: "
                .implode(', ', array_keys(PainAssessment::SKALA)).'.'
            );
        }

        $jenis = $data['kind'] ?? '';

        if (! array_key_exists($jenis, PainAssessment::JENIS)) {
            throw new ClinicalException("Jenis nyeri '{$jenis}' tidak dikenali.");
        }

        // Sengaja memeriksa keberadaan kunci, bukan memakai empty(): nol
        // adalah hasil penilaian yang sah dan justru yang paling sering
        // benar setelah nyeri berhasil ditangani.
        if (! array_key_exists('score', $data) || $data['score'] === null) {
            throw new ClinicalException('Skor nyeri wajib diisi. Nol adalah jawaban yang sah.');
        }

        $skor = $data['score'];
        $rentang = PainAssessment::SKALA[$alat];

        if (! is_int($skor) || $skor < $rentang['min'] || $skor > $rentang['max']) {
            throw new ClinicalException(sprintf(
                'Skor %s harus bilangan bulat %d sampai %d — itu rentang %s.',
                $alat, $rentang['min'], $rentang['max'], $rentang['label'],
            ));
        }

        if ($jenis === PainAssessment::TIDAK_ADA && $skor !== $rentang['min']) {
            throw new ClinicalException(sprintf(
                'Nyeri dinyatakan tidak ada tapi skornya %d. Nilai terendah %s adalah %d.',
                $skor, $rentang['label'], $rentang['min'],
            ));
        }

        $penilai = trim($data['assessed_by_name'] ?? $actor?->name ?? '');

        if ($penilai === '') {
            throw new ClinicalException('Nama penilai wajib dicatat.');
        }

        $evaluasi = $this->resolveEvaluatedIntervention($data['evaluates_intervention_id'] ?? null, $registrationId);

        return PainAssessment::query()->create([
            'registration_id' => $registrationId,
            'patient_id' => $kunjungan->patient_id,
            'registration_number' => $kunjungan->registration_number,
            'patient_mrn' => $kunjungan->patient_mrn,
            'patient_name' => $kunjungan->patient_name,
            'assessed_at' => $data['assessed_at'] ?? now(),
            'kind' => $jenis,
            'scale_type' => $alat,
            'score' => $skor,
            'provokes' => $data['provokes'] ?? null,
            'provokes_note' => $data['provokes_note'] ?? null,
            'quality' => $data['quality'] ?? null,
            'quality_note' => $data['quality_note'] ?? null,
            'location' => $data['location'] ?? null,
            // NULL berarti belum ditanyakan, bukan "tidak menyebar".
            'radiates' => $data['radiates'] ?? null,
            'radiates_to' => $data['radiates_to'] ?? null,
            'duration' => $data['duration'] ?? null,
            'relieved_by' => $this->validRelief($data['relieved_by'] ?? []),
            'relieved_by_note' => $data['relieved_by_note'] ?? null,
            'evaluates_intervention_id' => $evaluasi?->id,
            'assessed_by' => $actor?->id,
            'assessed_by_name' => $penilai,
            'note' => $data['note'] ?? null,
        ]);
    }

    /**
     * Mencatat penanganan atas sebuah penilaian nyeri.
     *
     * @throws ClinicalException
     */
    public function intervene(PainAssessment $assessment, array $data, ?User $actor = null): PainIntervention
    {
        $jenis = $data['kind'] ?? '';

        if (! array_key_exists($jenis, PainIntervention::JENIS)) {
            throw new ClinicalException(
                "Jenis intervensi '{$jenis}' tidak dikenali. Pilihannya: farmakologi, nonfarmakologi."
            );
        }

        $cara = trim($data['method'] ?? '');

        if ($cara === '') {
            throw new ClinicalException(
                'Nama obat atau tindakan wajib diisi. "Sudah ditangani" tanpa menyebut apa yang '
                .'dikerjakan tidak bisa dievaluasi maupun diulang.'
            );
        }

        if ($assessment->isPainFree()) {
            throw new ClinicalException(
                'Penilaian ini menyatakan pasien tidak nyeri, jadi tidak ada yang perlu ditangani. '
                .'Bila nyerinya muncul lagi, catat penilaian baru lebih dulu.'
            );
        }

        if ($jenis === PainIntervention::FARMAKOLOGI) {
            foreach (['dose' => 'Dosis', 'route' => 'Rute pemberian'] as $kolom => $label) {
                if (blank($data[$kolom] ?? null)) {
                    throw new ClinicalException(
                        "{$label} wajib diisi untuk intervensi farmakologi. \"Diberi analgetik\" tanpa "
                        .'dosis dan rutenya tidak bisa ditelusuri saat nyerinya ternyata tidak berkurang.'
                    );
                }
            }
        }

        $pelaksana = trim($data['given_by_name'] ?? $actor?->name ?? '');

        if ($pelaksana === '') {
            throw new ClinicalException('Nama pelaksana wajib dicatat.');
        }

        return PainIntervention::query()->create([
            'assessment_id' => $assessment->id,
            'registration_id' => $assessment->registration_id,
            'patient_id' => $assessment->patient_id,
            'kind' => $jenis,
            'method' => $cara,
            'dose' => $data['dose'] ?? null,
            'route' => $data['route'] ?? null,
            'drug_id' => $data['drug_id'] ?? null,
            'given_at' => $data['given_at'] ?? now(),
            'given_by' => $actor?->id,
            'given_by_name' => $pelaksana,
            'note' => $data['note'] ?? null,
        ]);
    }

    // ---------------------------------------------------------------- baca

    /**
     * Perjalanan nyeri satu kunjungan: penilaian, penanganan, penilaian
     * ulang, berurutan menurut waktunya.
     */
    public function timelineFor(int $registrationId): Collection
    {
        return PainAssessment::query()
            ->where('registration_id', $registrationId)
            ->with(['interventions', 'evaluatedIntervention'])
            ->orderBy('assessed_at')
            ->get();
    }

    /**
     * Intervensi yang sudah lewat tenggat tanpa penilaian ulang.
     *
     * Inilah gunanya lingkaran itu ditutup: daftar ini yang menjawab
     * pertanyaan "nyeri siapa yang sudah ditangani tapi belum dicek
     * hasilnya".
     */
    public function awaitingEvaluation(?int $registrationId = null): Collection
    {
        return PainIntervention::query()
            ->when($registrationId, fn ($q) => $q->where('registration_id', $registrationId))
            ->whereDoesntHave('evaluation')
            ->orderBy('given_at')
            ->get()
            ->filter(fn (PainIntervention $i) => $i->isAwaitingEvaluation())
            ->values();
    }

    /**
     * Apakah nyerinya berkurang setelah ditangani.
     *
     * Dibandingkan sebagai pecahan dari rentang alat ukurnya, bukan
     * angka mutlak: penilaian sebelum dan sesudah bisa memakai alat yang
     * berbeda ketika kesadaran pasien berubah.
     */
    public function improvementAfter(PainIntervention $intervention): ?float
    {
        $sebelum = $intervention->assessment?->normalisedScore();
        $sesudah = $intervention->evaluation?->normalisedScore();

        if ($sebelum === null || $sesudah === null) {
            return null;
        }

        return round($sebelum - $sesudah, 3);
    }

    // ------------------------------------------------------------ internal

    /**
     * @throws ClinicalException
     */
    private function resolveEvaluatedIntervention(?int $interventionId, int $registrationId): ?PainIntervention
    {
        if ($interventionId === null) {
            return null;
        }

        $intervensi = PainIntervention::query()->find($interventionId)
            ?? throw new ClinicalException('Intervensi yang dievaluasi tidak ditemukan.');

        if ($intervensi->registration_id !== $registrationId) {
            throw new ClinicalException(
                'Intervensi yang dievaluasi milik kunjungan lain. Penilaian ulang yang menunjuk '
                .'penanganan pasien lain akan terbaca sebagai perbaikan yang tidak pernah terjadi.'
            );
        }

        if ($intervensi->evaluation()->exists()) {
            throw new ClinicalException(
                'Intervensi ini sudah punya penilaian ulang. Penilaian berikutnya dicatat sebagai '
                .'penilaian baru, atau sebagai evaluasi atas intervensi berikutnya.'
            );
        }

        return $intervensi;
    }

    /**
     * @param  mixed  $nilai
     * @return array<int, string>
     *
     * @throws ClinicalException
     */
    private function validRelief($nilai): array
    {
        if (! is_array($nilai)) {
            throw new ClinicalException('Yang meredakan nyeri harus berupa daftar.');
        }

        $bersih = array_values(array_unique(array_filter($nilai, 'is_string')));
        $asing = array_diff($bersih, array_keys(PainAssessment::PEREDA));

        if ($asing !== []) {
            throw new ClinicalException(
                'Pereda nyeri tidak dikenali: '.implode(', ', $asing)
                .'. Pilihannya: '.implode(', ', array_keys(PainAssessment::PEREDA)).'.'
            );
        }

        return $bersih;
    }
}
