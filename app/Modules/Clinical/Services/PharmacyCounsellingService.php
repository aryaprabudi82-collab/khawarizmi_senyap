<?php

namespace App\Modules\Clinical\Services;

use App\Modules\Clinical\Models\PharmacyCounselling;
use App\Modules\Platform\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Konseling farmasi (domain M item I).
 *
 * DAFTAR OBAT DAN ALERGI DISALIN, TIDAK DIKETIK. Khanza menyediakan
 * obat_pemakaian varchar(700) — seluruh daftar obat dijejalkan ke satu
 * kolom teks — dan riwayat_alergi varchar(30), tiga puluh karakter untuk
 * riwayat alergi seorang pasien. Keduanya sudah tercatat di tempat lain:
 * obat pada resep yang diserahkan farmasi, alergi pada clinical.allergies.
 * Mengetiknya ulang di sini menghasilkan versi kedua yang lebih pendek
 * dan bisa berbeda.
 *
 * DIBEKUKAN SAAT FINALISASI, bukan saat dibuka: apoteker perlu melihat
 * daftar yang hidup selama konseling berlangsung, dan yang harus abadi
 * adalah keadaan pada saat konseling itu dinyatakan selesai.
 *
 * ISI KONSELING WAJIB SAAT DIFINALKAN. Konseling adalah percakapan;
 * catatan konseling tanpa isi percakapan tidak membuktikan percakapan
 * itu pernah terjadi. Ditegakkan service DAN basis data.
 */
class PharmacyCounsellingService
{
    private const REGISTRASI = 'encounter.v_registration_summary';

    private const RESEP = 'pharmacy.v_prescription_detail';

    public function __construct(private readonly ClinicalRecordService $records) {}

    /**
     * @throws ClinicalException
     */
    public function open(int $registrationId, array $data = [], ?User $actor = null): PharmacyCounselling
    {
        $kunjungan = DB::table(self::REGISTRASI)->where('id', $registrationId)->first()
            ?? throw new ClinicalException('Kunjungan tidak ditemukan atau sudah dibatalkan.');

        return PharmacyCounselling::query()->create([
            'registration_id' => $registrationId,
            'patient_id' => $kunjungan->patient_id,
            'registration_number' => $kunjungan->registration_number,
            'patient_mrn' => $kunjungan->patient_mrn,
            'patient_name' => $kunjungan->patient_name,
            'counselled_at' => $data['counselled_at'] ?? now(),
            'diagnosis' => $data['diagnosis'] ?? null,
            'complaint' => $data['complaint'] ?? null,
            // NULL, bukan false: belum ada yang menanyakannya.
            'is_repeat_visit' => $data['is_repeat_visit'] ?? null,
            'medications' => [],
            'allergies' => [],
            'pharmacist_id' => $data['pharmacist_id'] ?? null,
            'pharmacist_name' => $data['pharmacist_name'] ?? $actor?->name,
            'status' => PharmacyCounselling::DRAF,
            'recorded_by' => $actor?->id,
            'recorded_by_name' => $actor?->name,
        ]);
    }

    /**
     * @throws ClinicalException
     */
    public function save(PharmacyCounselling $counselling, array $data): PharmacyCounselling
    {
        if (! $counselling->isEditable()) {
            throw new ClinicalException(
                'Konseling yang sudah difinalkan tidak bisa diubah. Catat konseling baru bila memang ada '
                .'percakapan berikutnya — dua percakapan bukan satu catatan yang disunting.'
            );
        }

        $counselling->update(array_intersect_key($data, array_flip([
            'diagnosis', 'complaint', 'is_repeat_visit',
            'counselling_given', 'follow_up', 'pharmacist_id', 'pharmacist_name',
        ])));

        return $counselling->refresh();
    }

    /**
     * @throws ClinicalException
     */
    public function finalize(PharmacyCounselling $counselling, ?User $actor = null): PharmacyCounselling
    {
        if (! $counselling->isEditable()) {
            throw new ClinicalException(
                $counselling->status === PharmacyCounselling::FINAL
                    ? 'Konseling ini sudah difinalkan.'
                    : 'Konseling yang dibatalkan tidak bisa difinalkan.'
            );
        }

        if (blank($counselling->counselling_given)) {
            throw new ClinicalException(
                'Isi konseling wajib dicatat sebelum difinalkan. Konseling adalah percakapan, dan catatan '
                .'tanpa isi percakapan tidak membuktikan percakapan itu pernah terjadi.'
            );
        }

        if (blank($counselling->pharmacist_name)) {
            throw new ClinicalException('Nama apoteker yang melakukan konseling wajib disebut.');
        }

        $counselling->update([
            'medications' => $this->freezeMedications($counselling->registration_id),
            'allergies' => $this->freezeAllergies($counselling->patient_id),
            'status' => PharmacyCounselling::FINAL,
            'finalized_at' => now(),
            'finalized_by' => $actor?->id,
        ]);

        return $counselling->refresh();
    }

    /**
     * @throws ClinicalException
     */
    public function cancel(PharmacyCounselling $counselling, string $reason): PharmacyCounselling
    {
        $alasan = trim($reason);

        if ($alasan === '') {
            throw new ClinicalException('Alasan pembatalan wajib diisi.');
        }

        if ($counselling->status === PharmacyCounselling::DIBATALKAN) {
            throw new ClinicalException('Konseling ini sudah dibatalkan.');
        }

        $counselling->update([
            'status' => PharmacyCounselling::DIBATALKAN,
            'follow_up' => trim(($counselling->follow_up ? $counselling->follow_up.' ' : '')."[Dibatalkan: {$alasan}]"),
        ]);

        return $counselling->refresh();
    }

    // ---------------------------------------------------------------- baca

    public function forRegistration(int $registrationId): Collection
    {
        return PharmacyCounselling::query()
            ->where('registration_id', $registrationId)
            ->where('status', '<>', PharmacyCounselling::DIBATALKAN)
            ->orderBy('counselled_at')
            ->get();
    }

    /**
     * Bahan yang dilihat apoteker SELAMA konseling: daftar obat dan alergi
     * yang hidup, belum dibekukan.
     */
    public function context(int $registrationId, int $patientId): array
    {
        return [
            'medications' => $this->freezeMedications($registrationId),
            'allergies' => $this->freezeAllergies($patientId),
        ];
    }

    // ------------------------------------------------------------ internal

    /**
     * @return array<int, array<string, mixed>>
     */
    private function freezeMedications(int $registrationId): array
    {
        return collect(DB::table(self::RESEP)
            ->where('registration_id', $registrationId)
            ->whereNotNull('dispensed_at')
            ->orderBy('prescribed_at')
            ->get())
            ->map(fn ($item) => [
                'drug_name' => $item->drug_name,
                'kfa_code' => $item->kfa_code,
                'dosage_instruction' => $item->dosage_instruction,
                'prescription_number' => $item->prescription_number,
            ])
            ->all();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function freezeAllergies(int $patientId): array
    {
        return $this->records->allergiesFor($patientId)
            ->map(fn ($a) => [
                'substance' => $a->substance,
                'category' => $a->category,
                'reaction' => $a->reaction,
                'severity' => $a->severity,
            ])
            ->all();
    }
}
