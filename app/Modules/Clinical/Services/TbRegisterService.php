<?php

namespace App\Modules\Clinical\Services;

use App\Modules\Clinical\Models\TbCase;
use App\Modules\Clinical\Models\TbFollowup;
use App\Modules\Platform\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Register program TB (domain O item E).
 *
 * LIMA ATURAN.
 *
 * 1. "SEMBUH" MENUNTUT BUKTI BAKTERIOLOGIS NEGATIF pada akhir
 *    pengobatan. Ini pembeda satu-satunya dari "pengobatan lengkap",
 *    dan angka kesembuhan TB yang dilaporkan ke program nasional
 *    dihitung dari yang pertama saja. Menandai pasien sembuh tanpa
 *    pemeriksaan akhir yang negatif melebih-lebihkan keberhasilan
 *    program — dan yang dirugikan bukan rumah sakitnya melainkan
 *    perencanaan pengendalian TB nasional yang bersandar pada angka itu.
 *
 * 2. "TIDAK DILAKUKAN" BUKAN "NEGATIF". Pemeriksaan yang tidak pernah
 *    dikerjakan tidak boleh terbaca sebagai bukti kesembuhan.
 *
 * 3. SKORING ANAK HANYA UNTUK ANAK. Sistem skoring dipakai ketika
 *    konfirmasi bakteriologis sulit didapat pada anak; memasangnya pada
 *    dewasa menutupi bahwa pemeriksaan dahak yang seharusnya dikerjakan
 *    tidak dikerjakan.
 *
 * 4. STATUS HIV "TIDAK DIKETAHUI" ADALAH JAWABAN YANG SAH. Pasien TB
 *    yang belum dites berbeda dari yang hasilnya non-reaktif, dan
 *    justru yang pertama yang harus ditawari tes.
 *
 * 5. YANG LOLOS TETAP BISA DITAGIH. Aturan pertama ditegakkan di sini,
 *    tapi service bukan satu-satunya pintu ke tabel; auditCureClaims()
 *    menyebutkan kasus yang mengaku sembuh tanpa bukti, apa pun jalan
 *    masuknya.
 */
class TbRegisterService
{
    private const REGISTRASI = 'encounter.v_registration_summary';

    /**
     * Mendaftarkan kasus TB baru.
     *
     * @throws ClinicalException
     */
    public function register(int $patientId, array $data, ?User $actor = null): TbCase
    {
        $this->assertVocabulary($data, 'diagnosis_type', TbCase::TIPE_DIAGNOSIS, 'Tipe diagnosis', true);
        $this->assertVocabulary($data, 'treatment_history', TbCase::RIWAYAT, 'Riwayat pengobatan', true);
        $this->assertVocabulary($data, 'referral_source', TbCase::RUJUKAN, 'Sumber rujukan', false);
        $this->assertVocabulary($data, 'drug_source', TbCase::SUMBER_OBAT, 'Sumber obat', false);

        $lokasi = $data['anatomical_site'] ?? '';

        if (! in_array($lokasi, ['paru', 'ekstraparu'], true)) {
            throw new ClinicalException("Lokasi anatomi '{$lokasi}' tidak dikenali. Pilihannya: paru, ekstraparu.");
        }

        $statusHiv = $data['hiv_status'] ?? 'tidak-diketahui';

        if (! in_array($statusHiv, ['positif', 'negatif', 'tidak-diketahui'], true)) {
            throw new ClinicalException("Status HIV '{$statusHiv}' tidak dikenali.");
        }

        $umur = $data['age_years'] ?? null;
        $skor = $data['child_score'] ?? null;

        if ($skor !== null) {
            if ($umur === null) {
                throw new ClinicalException(
                    'Umur wajib diisi bila skoring anak dipakai: skoringnya hanya berlaku untuk anak, dan '
                    .'tanpa umur tidak ada yang bisa memastikan itu.'
                );
            }

            if ($umur >= TbCase::BATAS_UMUR_ANAK) {
                throw new ClinicalException(sprintf(
                    'Skoring TB anak tidak berlaku untuk pasien berumur %d tahun. Sistem skoring dipakai '
                    .'ketika konfirmasi bakteriologis sulit didapat pada anak; memasangnya pada dewasa '
                    .'menutupi bahwa pemeriksaan dahak yang seharusnya dikerjakan tidak dikerjakan.',
                    $umur,
                ));
            }
        }

        $pencatat = trim($data['recorded_by_name'] ?? $actor?->name ?? '');

        if ($pencatat === '') {
            throw new ClinicalException('Nama pencatat wajib diisi.');
        }

        $identitas = $this->patientIdentity($patientId, $data);
        $tanggal = Carbon::parse($data['registered_on'] ?? now());

        return TbCase::query()->create([
            'register_number' => $data['register_number'] ?? $this->allocateNumber(),
            'patient_id' => $patientId,
            'registration_id' => $data['registration_id'] ?? null,
            'patient_mrn' => $identitas['mrn'],
            'patient_name' => $identitas['name'],
            'age_years' => $umur,
            'registered_on' => $tanggal->toDateString(),
            'report_quarter' => $data['report_quarter'] ?? (int) ceil($tanggal->month / 3),
            'report_year' => $data['report_year'] ?? $tanggal->year,
            'referral_source' => $data['referral_source'] ?? null,
            'diagnosis_type' => $data['diagnosis_type'],
            'anatomical_site' => $lokasi,
            'treatment_history' => $data['treatment_history'],
            'hiv_status' => $statusHiv,
            'hiv_tested_on' => $data['hiv_tested_on'] ?? null,
            'hiv_test_result' => $data['hiv_test_result'] ?? null,
            'child_score' => $skor,
            'child_score_note' => $data['child_score_note'] ?? null,
            'treatment_started_on' => $data['treatment_started_on'] ?? null,
            'regimen' => $data['regimen'] ?? null,
            'drug_source' => $data['drug_source'] ?? null,
            'outcome' => TbCase::BELUM,
            'note' => $data['note'] ?? null,
            'recorded_by' => $actor?->id,
            'recorded_by_name' => $pencatat,
        ]);
    }

    /**
     * Mencatat pemeriksaan lanjutan.
     *
     * @throws ClinicalException
     */
    public function recordFollowup(TbCase $case, string $phase, array $data): TbFollowup
    {
        if (! array_key_exists($phase, TbFollowup::TAHAP)) {
            throw new ClinicalException(
                "Tahap pemeriksaan '{$phase}' tidak dikenali. Pilihannya: "
                .implode(', ', array_keys(TbFollowup::TAHAP)).'.'
            );
        }

        $bta = $data['smear_result'] ?? null;

        if ($bta !== null && ! array_key_exists($bta, TbFollowup::HASIL_BTA)) {
            throw new ClinicalException("Hasil BTA '{$bta}' tidak dikenali.");
        }

        return TbFollowup::query()->updateOrCreate(
            ['tb_case_id' => $case->id, 'phase' => $phase],
            [
                'examined_on' => $data['examined_on'] ?? now()->toDateString(),
                'smear_result' => $bta,
                'rapid_test_result' => $data['rapid_test_result'] ?? null,
                'culture_result' => $data['culture_result'] ?? null,
                'lab_register_number' => $data['lab_register_number'] ?? null,
            ]
        );
    }

    /**
     * Menutup kasus dengan hasil akhir.
     *
     * @throws ClinicalException
     */
    public function close(TbCase $case, string $outcome, array $data = []): TbCase
    {
        if (! array_key_exists($outcome, TbCase::HASIL_AKHIR) || $outcome === TbCase::BELUM) {
            throw new ClinicalException(
                "Hasil akhir '{$outcome}' tidak dikenali. Pilihannya: "
                .implode(', ', array_diff(array_keys(TbCase::HASIL_AKHIR), [TbCase::BELUM])).'.'
            );
        }

        if ($case->isClosed()) {
            throw new ClinicalException(
                "Kasus {$case->register_number} sudah punya hasil akhir '{$case->outcome}'."
            );
        }

        if ($outcome === TbCase::SEMBUH && ! $case->load('followups')->hasNegativeEndOfTreatmentSmear()) {
            throw new ClinicalException(
                'Hasil akhir "sembuh" menuntut pemeriksaan akhir pengobatan dengan hasil NEGATIF. '
                .'Tanpa buktinya, yang benar adalah "pengobatan lengkap" — keduanya sama-sama berarti '
                .'pengobatan selesai, dan bedanya cuma ada tidaknya bukti bakteriologis. Angka kesembuhan '
                .'TB nasional dihitung dari yang pertama saja, jadi menandainya keliru melebih-lebihkan '
                .'keberhasilan program.'
            );
        }

        $case->update([
            'outcome' => $outcome,
            'outcome_on' => $data['outcome_on'] ?? now()->toDateString(),
            'note' => $data['note'] ?? $case->note,
        ]);

        return $case->refresh();
    }

    // ---------------------------------------------------------------- baca

    /**
     * Kasus yang mengaku sembuh tanpa bukti bakteriologis negatif.
     *
     * Aturannya ditegakkan saat penutupan, tapi service bukan satu-
     * satunya pintu ke tabel — dan pada angka yang dipakai perencanaan
     * nasional, satu lapis saja tidak cukup.
     */
    public function auditCureClaims(): Collection
    {
        return TbCase::query()
            ->where('outcome', TbCase::SEMBUH)
            ->with('followups')
            ->get()
            ->reject(fn (TbCase $k) => $k->hasNegativeEndOfTreatmentSmear())
            ->values();
    }

    /** Kasus TB yang status HIV-nya belum diketahui. */
    public function awaitingHivTest(): Collection
    {
        return TbCase::query()
            ->where('hiv_status', 'tidak-diketahui')
            ->where('outcome', TbCase::BELUM)
            ->orderBy('registered_on')
            ->get();
    }

    /**
     * Angka keberhasilan pengobatan satu triwulan.
     *
     * Sembuh dan pengobatan lengkap DIPISAH, bukan dijumlah begitu saja
     * — laporannya memang menuntut keduanya terlihat sendiri-sendiri.
     *
     * @return array<string, int|float>
     */
    public function outcomeRecap(int $year, int $quarter): array
    {
        $kasus = TbCase::query()
            ->where('report_year', $year)
            ->where('report_quarter', $quarter)
            ->get();

        $rekap = ['total' => $kasus->count()];

        foreach (array_keys(TbCase::HASIL_AKHIR) as $hasil) {
            $rekap[$hasil] = $kasus->where('outcome', $hasil)->count();
        }

        $selesai = $kasus->whereIn('outcome', TbCase::BERHASIL)->count();

        $rekap['angka_keberhasilan'] = $rekap['total'] > 0
            ? round($selesai / $rekap['total'] * 100, 2)
            : 0.0;

        return $rekap;
    }

    // ------------------------------------------------------------ internal

    /**
     * @param  array<string, string>  $kosakata
     *
     * @throws ClinicalException
     */
    private function assertVocabulary(array $data, string $key, array $kosakata, string $label, bool $wajib): void
    {
        $nilai = $data[$key] ?? null;

        if ($nilai === null) {
            if ($wajib) {
                throw new ClinicalException("{$label} wajib diisi.");
            }

            return;
        }

        if (! array_key_exists($nilai, $kosakata)) {
            throw new ClinicalException(
                "{$label} '{$nilai}' tidak dikenali. Pilihannya: ".implode(', ', array_keys($kosakata)).'.'
            );
        }
    }

    /**
     * @return array{mrn: string, name: string}
     *
     * @throws ClinicalException
     */
    private function patientIdentity(int $patientId, array $data): array
    {
        if (filled($data['patient_mrn'] ?? null) && filled($data['patient_name'] ?? null)) {
            return ['mrn' => $data['patient_mrn'], 'name' => $data['patient_name']];
        }

        $kunjungan = DB::table(self::REGISTRASI)
            ->where('patient_id', $patientId)
            ->orderByDesc('service_date')
            ->first();

        if ($kunjungan !== null) {
            return ['mrn' => $kunjungan->patient_mrn, 'name' => $kunjungan->patient_name];
        }

        throw new ClinicalException(
            'Pasien ini belum punya kunjungan, jadi identitasnya belum bisa disalin ke register TB.'
        );
    }

    private function allocateNumber(): string
    {
        $prefix = now()->format('Ymd');

        $row = DB::selectOne(
            'INSERT INTO clinical.number_sequences (prefix, last_number, updated_at)
             VALUES (?, 1, now())
             ON CONFLICT (prefix) DO UPDATE
                SET last_number = clinical.number_sequences.last_number + 1,
                    updated_at  = now()
             RETURNING last_number',
            [$prefix]
        );

        return 'TB'.$prefix.'-'.str_pad((string) $row->last_number, 4, '0', STR_PAD_LEFT);
    }
}
