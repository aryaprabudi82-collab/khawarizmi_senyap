<?php

namespace App\Modules\Clinical\Services;

use App\Modules\Catalog\Services\TariffLookup;
use App\Modules\Clinical\Models\Allergy;
use App\Modules\Clinical\Models\Assessment;
use App\Modules\Clinical\Models\AssessmentRevision;
use App\Modules\Clinical\Models\Diagnosis;
use App\Modules\Clinical\Models\Observation;
use App\Modules\Clinical\Models\Procedure;
use App\Modules\Clinical\Models\Screening;
use App\Modules\Platform\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Pintu masuk konteks clinical.
 *
 * Aturan Permenkes 24/2022 yang ditegakkan di sini:
 *  - Asesmen yang sudah difinalkan tidak bisa disunting di tempat. Ralat
 *    membuat versi baru, versi lamanya diarsipkan utuh berikut alasannya.
 *  - Setiap pencatatan membawa identitas praktisi dan waktu klinisnya.
 */
class ClinicalRecordService
{
    public function __construct(
        private readonly RegistrationContext $registrations,
        private readonly TariffLookup $tariffs,
    ) {}

    /**
     * Membuka asesmen untuk satu kunjungan, membuat draf bila belum ada.
     *
     * @throws ClinicalException
     */
    public function openAssessment(int $registrationId, string $kind, ?User $actor = null): Assessment
    {
        $kunjungan = $this->registrations->find($registrationId)
            ?? throw new ClinicalException('Kunjungan tidak ditemukan atau sudah dibatalkan.');

        $existing = Assessment::query()
            ->where('registration_id', $registrationId)
            ->where('kind', $kind)
            ->first();

        if ($existing !== null) {
            return $existing;
        }

        return Assessment::query()->create([
            'registration_id' => $kunjungan->id,
            'patient_id' => $kunjungan->patient_id,
            'registration_number' => $kunjungan->registration_number,
            'patient_mrn' => $kunjungan->patient_mrn,
            'patient_name' => $kunjungan->patient_name,
            'unit_name' => $kunjungan->unit_name,
            'kind' => $kind,
            'practitioner_id' => $kunjungan->practitioner_id,
            'practitioner_name' => $kunjungan->practitioner_name,
            'recorded_at' => now(),
            'status' => Assessment::STATUS_DRAFT,
            'version' => 1,
            'created_by' => $actor?->id,
        ]);
    }

    /**
     * Menyimpan isi asesmen.
     *
     * Selama masih draf, isian ditimpa biasa. Begitu sudah final, perubahan
     * apa pun wajib menyertakan alasan dan menghasilkan versi baru.
     *
     * @throws ClinicalException
     */
    public function saveAssessment(
        Assessment $assessment,
        array $content,
        ?User $actor = null,
        ?string $reason = null,
    ): Assessment {
        $content = collect($content)
            ->only(Assessment::CONTENT_FIELDS)
            ->all();

        if (! $assessment->isLocked()) {
            $assessment->update($content);

            return $assessment->refresh();
        }

        if ($reason === null || trim($reason) === '') {
            throw new ClinicalException(
                'Asesmen yang sudah difinalkan hanya bisa diralat dengan menyertakan alasan.'
            );
        }

        return DB::transaction(function () use ($assessment, $content, $actor, $reason): Assessment {
            // Versi berjalan diarsipkan lebih dulu, baru isinya diganti.
            AssessmentRevision::query()->create([
                'assessment_id' => $assessment->id,
                'version' => $assessment->version,
                'content' => $assessment->contentSnapshot(),
                'reason' => trim($reason),
                'revised_by' => $actor?->id,
                'revised_by_name' => $actor?->name,
                'revised_at' => now(),
            ]);

            $assessment->update($content + [
                'version' => $assessment->version + 1,
                'status' => Assessment::STATUS_AMENDED,
            ]);

            return $assessment->refresh();
        });
    }

    /**
     * Mengunci asesmen.
     *
     * @throws ClinicalException
     */
    public function finalizeAssessment(Assessment $assessment, ?User $actor = null): Assessment
    {
        if ($assessment->isLocked()) {
            throw new ClinicalException('Asesmen ini sudah difinalkan.');
        }

        $terisi = collect($assessment->contentSnapshot())
            ->filter(fn ($nilai) => filled($nilai));

        if ($terisi->isEmpty()) {
            throw new ClinicalException('Asesmen kosong tidak bisa difinalkan.');
        }

        $assessment->update([
            'status' => Assessment::STATUS_FINAL,
            'finalized_at' => now(),
            'finalized_by' => $actor?->id,
        ]);

        return $assessment->refresh();
    }

    /**
     * Mencatat tanda vital.
     *
     * @param  array<string, float|null>  $values  kode pengukuran => nilai
     * @return int jumlah pengukuran yang tersimpan
     */
    public function recordObservations(
        Assessment $assessment,
        array $values,
        ?User $actor = null,
    ): int {
        $waktu = now();
        $baris = [];

        foreach ($values as $code => $value) {
            if ($value === null || $value === '' || ! isset(Observation::CATALOG[$code])) {
                continue;
            }

            [$display, $unit] = Observation::CATALOG[$code];
            $numeric = (float) $value;

            $baris[] = [
                'observed_at' => $waktu,
                'registration_id' => $assessment->registration_id,
                'patient_id' => $assessment->patient_id,
                'assessment_id' => $assessment->id,
                'code' => $code,
                'display' => $display,
                'value_numeric' => $numeric,
                'unit' => $unit,
                'is_abnormal' => Observation::isAbnormal($code, $numeric),
                'practitioner_id' => $assessment->practitioner_id,
                'created_by' => $actor?->id,
                'created_at' => $waktu,
            ];
        }

        if ($baris === []) {
            return 0;
        }

        // Pengukuran bersifat menambah, tidak menimpa: nilai lama tetap ada
        // sebagai riwayat tren.
        DB::table('clinical.observations')->insert($baris);

        return count($baris);
    }

    /** Pengukuran terbaru per jenis untuk satu kunjungan. */
    public function latestObservations(int $registrationId): Collection
    {
        return Observation::query()
            ->where('registration_id', $registrationId)
            // id ikut jadi kunci urutan: dua pengukuran bisa jatuh pada detik
            // yang sama, dan tanpa ini nilai lama bisa menang secara acak.
            ->orderByDesc('observed_at')
            ->orderByDesc('id')
            ->get()
            ->unique('code')
            ->keyBy('code');
    }

    /**
     * @throws ClinicalException
     */
    public function addDiagnosis(
        Assessment $assessment,
        string $code,
        string $display,
        string $rank = Diagnosis::RANK_SEKUNDER,
        string $certainty = 'kerja',
        ?string $note = null,
        ?User $actor = null,
    ): Diagnosis {
        $sudahAda = Diagnosis::query()
            ->where('registration_id', $assessment->registration_id)
            ->where('code', $code)
            ->exists();

        if ($sudahAda) {
            throw new ClinicalException("Diagnosis {$code} sudah tercatat pada kunjungan ini.");
        }

        if ($rank === Diagnosis::RANK_UTAMA) {
            $adaUtama = Diagnosis::query()
                ->where('registration_id', $assessment->registration_id)
                ->where('rank', Diagnosis::RANK_UTAMA)
                ->exists();

            if ($adaUtama) {
                throw new ClinicalException(
                    'Kunjungan ini sudah punya diagnosis utama. Hapus dulu yang lama bila ingin menggantinya.'
                );
            }
        }

        return Diagnosis::query()->create([
            'registration_id' => $assessment->registration_id,
            'patient_id' => $assessment->patient_id,
            'registration_number' => $assessment->registration_number,
            'code' => $code,
            // Disalin, bukan dirujuk: kamus ICD bisa direvisi, diagnosis yang
            // sudah tercatat tidak boleh ikut berubah.
            'display' => $display,
            'rank' => $rank,
            'certainty' => $certainty,
            'note' => $note,
            'practitioner_id' => $assessment->practitioner_id,
            'practitioner_name' => $assessment->practitioner_name,
            'diagnosed_at' => now(),
            'created_by' => $actor?->id,
        ]);
    }

    public function diagnosesFor(int $registrationId): Collection
    {
        return Diagnosis::query()
            ->where('registration_id', $registrationId)
            ->orderByRaw("CASE rank WHEN 'utama' THEN 1 WHEN 'komplikasi' THEN 2 ELSE 3 END")
            ->get();
    }

    /**
     * @throws ClinicalException
     */
    public function recordAllergy(
        int $patientId,
        string $substance,
        array $attributes = [],
        ?int $registrationId = null,
        ?User $actor = null,
    ): Allergy {
        $sudahAda = Allergy::query()
            ->where('patient_id', $patientId)
            ->aktif()
            ->whereRaw('lower(substance) = lower(?)', [$substance])
            ->exists();

        if ($sudahAda) {
            throw new ClinicalException("Alergi terhadap {$substance} sudah tercatat.");
        }

        return Allergy::query()->create([
            'patient_id' => $patientId,
            'recorded_in_registration_id' => $registrationId,
            'substance' => $substance,
            'category' => $attributes['category'] ?? 'obat',
            'reaction' => $attributes['reaction'] ?? null,
            'severity' => $attributes['severity'] ?? 'sedang',
            'status' => Allergy::STATUS_AKTIF,
            'practitioner_id' => $attributes['practitioner_id'] ?? null,
            'practitioner_name' => $attributes['practitioner_name'] ?? null,
            'recorded_at' => now(),
            'created_by' => $actor?->id,
        ]);
    }

    public function allergiesFor(int $patientId): Collection
    {
        return Allergy::query()
            ->where('patient_id', $patientId)
            ->aktif()
            ->orderByRaw("CASE severity WHEN 'berat' THEN 1 WHEN 'sedang' THEN 2 ELSE 3 END")
            ->get();
    }

    /**
     * sekrining_rawat_jalan — dicatat sekali per kunjungan, sebelum asesmen
     * penuh. Lihat catatan migrasi screenings untuk alasan tidak memakai
     * pola draft/final/amended seperti assessments.
     *
     * @throws ClinicalException
     */
    public function recordScreening(int $registrationId, array $data, ?User $actor = null): Screening
    {
        if (Screening::query()->where('registration_id', $registrationId)->exists()) {
            throw new ClinicalException('Kunjungan ini sudah punya catatan skrining.');
        }

        $kunjungan = $this->registrations->find($registrationId)
            ?? throw new ClinicalException('Kunjungan tidak ditemukan atau sudah dibatalkan.');

        return Screening::query()->create($data + [
            'registration_id' => $kunjungan->id,
            'patient_id' => $kunjungan->patient_id,
            'registration_number' => $kunjungan->registration_number,
            'patient_mrn' => $kunjungan->patient_mrn,
            'patient_name' => $kunjungan->patient_name,
            'screened_by' => $actor?->id,
            'screened_by_name' => $actor?->name,
            'screened_at' => now(),
        ]);
    }

    public function screeningFor(int $registrationId): ?Screening
    {
        return Screening::query()->where('registration_id', $registrationId)->first();
    }

    /**
     * tindakan_ralan — tarif diambil dari catalog.tariffs milik penjamin
     * kunjungan ini dan DISALIN ke unit_price/amount, bukan dirujuk live.
     * Lihat catatan migrasi procedures.
     *
     * @throws ClinicalException
     */
    public function recordProcedure(
        int $registrationId,
        string $serviceCode,
        float $quantity,
        ?string $note,
        ?User $actor = null,
    ): Procedure {
        $kunjungan = $this->registrations->find($registrationId)
            ?? throw new ClinicalException('Kunjungan tidak ditemukan atau sudah dibatalkan.');

        $layanan = $this->tariffs->findServiceByCode($serviceCode)
            ?? throw new ClinicalException("Tindakan {$serviceCode} tidak ada di katalog layanan.");

        $tarif = $this->tariffs->resolve(
            serviceCode: $serviceCode,
            payerId: $kunjungan->payer_id,
            on: now(),
        );

        if ($tarif === null) {
            throw new ClinicalException(
                "Tarif {$layanan->name} untuk penjamin {$kunjungan->payer_name} belum ditetapkan."
            );
        }

        return Procedure::query()->create([
            'registration_id' => $kunjungan->id,
            'patient_id' => $kunjungan->patient_id,
            'registration_number' => $kunjungan->registration_number,
            'patient_mrn' => $kunjungan->patient_mrn,
            'patient_name' => $kunjungan->patient_name,
            'service_id' => $layanan->id,
            'service_code' => $layanan->code,
            'service_name' => $layanan->name,
            'quantity' => $quantity,
            'unit_price' => $tarif,
            'amount' => round($tarif * $quantity, 2),
            'practitioner_id' => $kunjungan->practitioner_id,
            'practitioner_name' => $kunjungan->practitioner_name,
            'performed_at' => now(),
            'note' => $note,
            'created_by' => $actor?->id,
        ]);
    }

    public function proceduresFor(int $registrationId): Collection
    {
        return Procedure::query()
            ->where('registration_id', $registrationId)
            ->orderByDesc('performed_at')
            ->get();
    }
}
