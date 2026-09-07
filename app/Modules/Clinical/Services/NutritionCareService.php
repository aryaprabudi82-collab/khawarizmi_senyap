<?php

namespace App\Modules\Clinical\Services;

use App\Modules\Clinical\Models\Allergy;
use App\Modules\Clinical\Models\NutritionAssessment;
use App\Modules\Clinical\Models\NutritionNote;
use App\Modules\Platform\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Asuhan gizi (domain M item K).
 *
 * EMPAT ATURAN.
 *
 * 1. ALERGI MAKANAN MASUK KE DAFTAR ALERGI PASIEN. Khanza memberi
 *    asuhan_gizi tujuh kolom alergen tetap — telur, susu sapi, kacang,
 *    gluten, udang, ikan, hazelnut — sehingga alergen kedelapan tidak
 *    punya tempat, dan yang tercatat di situ tidak terbaca oleh telaah
 *    resep maupun resume medis. Di sini asuhan gizi menulis ke
 *    clinical.allergies dengan kategori makanan, sama seperti
 *    rekonsiliasi obat pada item I.
 *
 * 2. INDEKS ANTROPOMETRI DIHITUNG, TIDAK DISIMPAN — dan yang tidak bisa
 *    dihitung dinyatakan belum tersedia, bukan dikarang. IMT dan berat
 *    badan ideal dewasa punya rumus baku. BB/U, TB/U, BB/TB, dan LLA/U
 *    anak menuntut tabel standar pertumbuhan WHO yang belum diimpor.
 *
 * 3. UMUR DAN JENIS KELAMIN DISALIN SAAT PENGKAJIAN. Indeks anak
 *    bergantung pada umur pada hari pengukuran; umur yang dihitung ulang
 *    bertahun kemudian menghasilkan indeks yang berbeda dari yang dibaca
 *    ahli gizinya waktu itu.
 *
 * 4. CATATAN YANG SELURUH BAGIANNYA KOSONG DITOLAK. Khanza membiarkan
 *    keenam kolom catatan_adime_gizi NULL sekaligus, sehingga baris
 *    kosong bisa tercipta dan terhitung sebagai kunjungan ahli gizi.
 */
class NutritionCareService
{
    private const REGISTRASI = 'encounter.v_registration_summary';

    public function __construct(private readonly ClinicalRecordService $records) {}

    /**
     * Membuka asuhan gizi untuk satu kunjungan pada satu hari.
     *
     * @throws ClinicalException
     */
    public function open(int $registrationId, array $data = [], ?User $actor = null): NutritionAssessment
    {
        $kunjungan = DB::table(self::REGISTRASI)->where('id', $registrationId)->first()
            ?? throw new ClinicalException('Kunjungan tidak ditemukan atau sudah dibatalkan.');

        $tanggal = $data['assessed_on'] ?? now()->toDateString();

        $ada = NutritionAssessment::query()
            ->where('registration_id', $registrationId)
            ->whereDate('assessed_on', $tanggal)
            ->where('status', '<>', NutritionAssessment::DIBATALKAN)
            ->first();

        if ($ada !== null) {
            return $ada;
        }

        return NutritionAssessment::query()->create([
            'registration_id' => $registrationId,
            'patient_id' => $kunjungan->patient_id,
            'registration_number' => $kunjungan->registration_number,
            'patient_mrn' => $kunjungan->patient_mrn,
            'patient_name' => $kunjungan->patient_name,
            'assessed_on' => $tanggal,
            // Disalin, bukan dirujuk — lihat aturan 3 pada catatan kelas.
            'age_months' => $data['age_months'] ?? null,
            'sex' => $data['sex'] ?? null,
            'food_allergies' => [],
            'dietitian_id' => $data['dietitian_id'] ?? null,
            'dietitian_name' => $data['dietitian_name'] ?? $actor?->name,
            'status' => NutritionAssessment::DRAF,
            'recorded_by' => $actor?->id,
            'recorded_by_name' => $actor?->name,
        ]);
    }

    /**
     * @throws ClinicalException
     */
    public function save(NutritionAssessment $assessment, array $data): NutritionAssessment
    {
        $this->assertEditable($assessment);

        $assessment->update(array_intersect_key($data, array_flip([
            'age_months', 'sex',
            'weight_kg', 'height_cm', 'mid_upper_arm_cm', 'knee_height_cm', 'ulna_length_cm',
            'biochemistry', 'physical_clinical', 'eating_pattern', 'personal_history',
            'nutrition_diagnosis', 'intervention', 'monitoring_plan',
            'dietitian_id', 'dietitian_name',
        ])));

        return $assessment->refresh();
    }

    /**
     * Mencatat alergi makanan yang ditemukan saat pengkajian gizi.
     *
     * Masuk ke DAFTAR ALERGI PASIEN, bukan ke kolom alergen tetap.
     *
     * @throws ClinicalException
     */
    public function recordFoodAllergy(
        NutritionAssessment $assessment,
        string $substance,
        array $attributes = [],
        ?User $actor = null,
    ): Allergy {
        $this->assertEditable($assessment);

        return $this->records->recordAllergy(
            $assessment->patient_id,
            $substance,
            $attributes + ['category' => 'makanan'],
            $assessment->registration_id,
            $actor,
        );
    }

    /**
     * @throws ClinicalException
     */
    public function finalize(NutritionAssessment $assessment, ?User $actor = null): NutritionAssessment
    {
        $this->assertEditable($assessment);

        if (blank($assessment->nutrition_diagnosis)) {
            throw new ClinicalException(
                'Diagnosis gizi wajib diisi sebelum asuhan difinalkan. Tanpa diagnosis, intervensi gizi '
                .'tidak punya sasaran dan tidak bisa dievaluasi.'
            );
        }

        if (blank($assessment->dietitian_name)) {
            throw new ClinicalException('Nama ahli gizi penyusun asuhan wajib disebut.');
        }

        $assessment->update([
            'food_allergies' => $this->freezeFoodAllergies($assessment->patient_id),
            'status' => NutritionAssessment::FINAL,
            'finalized_at' => now(),
            'finalized_by' => $actor?->id,
        ]);

        return $assessment->refresh();
    }

    /**
     * @throws ClinicalException
     */
    public function cancel(NutritionAssessment $assessment, string $reason): NutritionAssessment
    {
        $alasan = trim($reason);

        if ($alasan === '') {
            throw new ClinicalException('Alasan pembatalan wajib diisi.');
        }

        if ($assessment->status === NutritionAssessment::DIBATALKAN) {
            throw new ClinicalException('Asuhan gizi ini sudah dibatalkan.');
        }

        $assessment->update([
            'status' => NutritionAssessment::DIBATALKAN,
            'personal_history' => trim(
                ($assessment->personal_history ? $assessment->personal_history.' ' : '')."[Dibatalkan: {$alasan}]"
            ),
        ]);

        return $assessment->refresh();
    }

    // -------------------------------------------------------------- catatan

    /**
     * Mencatat perkembangan ADIME.
     *
     * @throws ClinicalException
     */
    public function addNote(
        int $registrationId,
        array $parts,
        array $data = [],
        ?User $actor = null,
    ): NutritionNote {
        $kunjungan = DB::table(self::REGISTRASI)->where('id', $registrationId)->first()
            ?? throw new ClinicalException('Kunjungan tidak ditemukan atau sudah dibatalkan.');

        $isi = array_intersect_key($parts, NutritionNote::BAGIAN);
        $terisi = array_filter($isi, fn ($nilai) => filled($nilai));

        if ($terisi === []) {
            throw new ClinicalException(
                'Catatan gizi harus mengisi setidaknya satu bagian ADIME. Baris kosong akan terhitung '
                .'sebagai kunjungan ahli gizi yang sebenarnya tidak berisi apa-apa.'
            );
        }

        $jenis = $data['kind'] ?? NutritionNote::ADIME;

        if (! in_array($jenis, [NutritionNote::ADIME, NutritionNote::MONITORING], true)) {
            throw new ClinicalException("Jenis catatan '{$jenis}' tidak dikenali.");
        }

        $asuhan = isset($data['assessment_id'])
            ? NutritionAssessment::query()->find($data['assessment_id'])
            : null;

        if ($asuhan !== null && $asuhan->registration_id !== $registrationId) {
            throw new ClinicalException(
                'Asuhan gizi yang dipantau bukan milik kunjungan ini. Catatan pemantauan yang menunjuk '
                .'asuhan pasien lain akan terbaca sebagai perkembangan pasien yang salah.'
            );
        }

        return NutritionNote::query()->create($isi + [
            'registration_id' => $registrationId,
            'patient_id' => $kunjungan->patient_id,
            'assessment_id' => $asuhan?->id,
            'registration_number' => $kunjungan->registration_number,
            'patient_mrn' => $kunjungan->patient_mrn,
            'patient_name' => $kunjungan->patient_name,
            'noted_at' => $data['noted_at'] ?? now(),
            'kind' => $jenis,
            'dietitian_id' => $data['dietitian_id'] ?? null,
            'dietitian_name' => $data['dietitian_name'] ?? $actor?->name,
            'recorded_by' => $actor?->id,
            'recorded_by_name' => $actor?->name,
        ]);
    }

    // ---------------------------------------------------------------- baca

    public function forRegistration(int $registrationId): Collection
    {
        return NutritionAssessment::query()
            ->where('registration_id', $registrationId)
            ->where('status', '<>', NutritionAssessment::DIBATALKAN)
            ->with('notes')
            ->orderBy('assessed_on')
            ->get();
    }

    public function notesFor(int $registrationId): Collection
    {
        return NutritionNote::query()
            ->where('registration_id', $registrationId)
            ->orderBy('noted_at')
            ->get();
    }

    /**
     * Riwayat berat badan seorang pasien.
     *
     * Inilah yang membuat antropometri berupa angka berguna: perubahan
     * berat dari waktu ke waktu adalah inti pemantauan gizi, dan char(5)
     * milik Khanza tidak bisa ditrenkan.
     *
     * @return array<int, array<string, mixed>>
     */
    public function weightTrend(int $patientId, int $limit = 24): array
    {
        return NutritionAssessment::query()
            ->where('patient_id', $patientId)
            ->whereNotNull('weight_kg')
            ->where('status', '<>', NutritionAssessment::DIBATALKAN)
            ->orderBy('assessed_on')
            ->limit($limit)
            ->get()
            ->map(fn (NutritionAssessment $a) => [
                'assessed_on' => $a->assessed_on->toDateString(),
                'weight_kg' => (float) $a->weight_kg,
                'bmi' => $a->bmi(),
                'bmi_category' => $a->bmiCategory(),
            ])
            ->all();
    }

    // ------------------------------------------------------------ internal

    /**
     * @return array<int, array<string, mixed>>
     */
    private function freezeFoodAllergies(int $patientId): array
    {
        return $this->records->allergiesFor($patientId)
            ->filter(fn ($a) => $a->category === 'makanan')
            ->map(fn ($a) => [
                'substance' => $a->substance,
                'reaction' => $a->reaction,
                'severity' => $a->severity,
            ])
            ->values()
            ->all();
    }

    /**
     * @throws ClinicalException
     */
    private function assertEditable(NutritionAssessment $assessment): void
    {
        if ($assessment->isEditable()) {
            return;
        }

        throw new ClinicalException(
            $assessment->status === NutritionAssessment::FINAL
                ? 'Asuhan gizi yang sudah difinalkan tidak bisa diubah. Catat perkembangannya sebagai '
                    .'catatan ADIME, bukan dengan menyunting asuhan yang sudah ditutup.'
                : 'Asuhan gizi ini sudah dibatalkan.'
        );
    }
}
