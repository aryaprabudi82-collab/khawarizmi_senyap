<?php

namespace App\Modules\Clinical\Services;

use App\Modules\Clinical\Models\DialysisObservation;
use App\Modules\Clinical\Models\DialysisSerology;
use App\Modules\Clinical\Models\DialysisSession;
use App\Modules\Platform\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Hemodialisa (domain M item P).
 *
 * LIMA ATURAN.
 *
 * 1. LAMA DIALISIS DIHITUNG dari jam mulai dan jam selesai. Khanza
 *    menyimpan kolom `lama` yang diketik tanpa punya kedua jam itu, dan
 *    durasi yang diketik cenderung terisi sesuai resep alih-alih sesuai
 *    kenyataan. Kecukupan dialisis bergantung pada durasi yang benar
 *    tercapai.
 *
 * 2. SEROLOGI MELEKAT PADA PASIEN, bukan disalin ke tiap sesi. Yang
 *    dibaca saat menjadwalkan mesin adalah satu baris yang masih
 *    berlaku, bukan salah satu dari ratusan salinan.
 *
 * 3. SESI TIDAK DIMULAI TANPA SEROLOGI YANG MASIH BERLAKU — kecuali
 *    dengan alasan tertulis. Bukan larangan mutlak: pasien darurat yang
 *    butuh dialisis segera tidak boleh ditunda menunggu laboratorium,
 *    dan sistem yang melarangnya akan dilewati dengan mencatat
 *    serologi karangan.
 *
 * 4. PARAMETER MESIN TERPISAH DARI TANDA VITAL PASIEN. Yang kedua sudah
 *    punya rumahnya di panel observasi sejak item D.
 *
 * 5. BALANS CAIRAN MEMAKAI MEKANISME YANG SAMA DENGAN BANGSAL. Jenis
 *    cairan khas HD sudah ada di catalog.fluid_items sejak item E;
 *    tabel balans kedua akan membuat balans pasien yang sama pada hari
 *    yang sama tidak bisa dijumlahkan.
 */
class DialysisService
{
    private const REGISTRASI = 'encounter.v_registration_summary';

    /**
     * Mencatat hasil pemeriksaan serologi seorang pasien.
     *
     * @throws ClinicalException
     */
    public function recordSerology(int $patientId, array $data, ?User $actor = null): DialysisSerology
    {
        foreach (['hbsag', 'anti_hcv', 'anti_hiv'] as $kolom) {
            $nilai = $data[$kolom] ?? DialysisSerology::BELUM_DIPERIKSA;

            if (! array_key_exists($nilai, DialysisSerology::HASIL)) {
                throw new ClinicalException(
                    "Hasil '{$nilai}' untuk {$kolom} tidak dikenali. Pilihannya: "
                    .implode(', ', array_keys(DialysisSerology::HASIL)).'.'
                );
            }

            $data[$kolom] = $nilai;
        }

        $identitas = $this->patientIdentity($patientId);

        return DialysisSerology::query()->updateOrCreate(
            [
                'patient_id' => $patientId,
                'tested_on' => $data['tested_on'] ?? now()->toDateString(),
            ],
            [
                'patient_mrn' => $identitas['mrn'],
                'patient_name' => $identitas['name'],
                'hbsag' => $data['hbsag'],
                'anti_hcv' => $data['anti_hcv'],
                'anti_hiv' => $data['anti_hiv'],
                'laboratory' => $data['laboratory'] ?? null,
                'reference_number' => $data['reference_number'] ?? null,
                'note' => $data['note'] ?? null,
                'recorded_by' => $actor?->id,
                'recorded_by_name' => $actor?->name,
            ]
        );
    }

    /** Serologi terbaru pasien, apa pun masa berlakunya. */
    public function latestSerology(int $patientId): ?DialysisSerology
    {
        return DialysisSerology::query()
            ->where('patient_id', $patientId)
            ->orderByDesc('tested_on')
            ->first();
    }

    /**
     * Apakah pasien menuntut mesin terpisah menurut serologi yang masih
     * berlaku.
     *
     * Mengembalikan null bila tidak ada serologi yang berlaku: "belum
     * diketahui" berbeda dari "tidak perlu", dan menyamakan keduanya
     * akan menempatkan pasien yang belum diperiksa pada mesin bersama.
     */
    public function requiresDedicatedMachine(int $patientId): ?bool
    {
        $serologi = $this->latestSerology($patientId);

        if ($serologi === null || ! $serologi->isValid()) {
            return null;
        }

        return $serologi->needsDedicatedMachine();
    }

    /**
     * Memulai sesi dialisis.
     *
     * @throws ClinicalException
     */
    public function start(int $registrationId, array $data = [], ?User $actor = null): DialysisSession
    {
        $kunjungan = DB::table(self::REGISTRASI)->where('id', $registrationId)->first()
            ?? throw new ClinicalException('Kunjungan tidak ditemukan atau sudah dibatalkan.');

        $berjalan = DialysisSession::query()
            ->where('registration_id', $registrationId)
            ->where('status', '<>', DialysisSession::DIBATALKAN)
            ->first();

        if ($berjalan !== null) {
            return $berjalan;
        }

        $this->assertSerologyKnown((int) $kunjungan->patient_id, $data);

        if (isset($data['access_type']) && ! array_key_exists($data['access_type'], DialysisSession::AKSES)) {
            throw new ClinicalException(
                "Jenis akses '{$data['access_type']}' tidak dikenali. Pilihannya: "
                .implode(', ', array_keys(DialysisSession::AKSES)).'.'
            );
        }

        return DialysisSession::query()->create([
            'registration_id' => $registrationId,
            'patient_id' => $kunjungan->patient_id,
            'registration_number' => $kunjungan->registration_number,
            'patient_mrn' => $kunjungan->patient_mrn,
            'patient_name' => $kunjungan->patient_name,
            'practitioner_id' => $data['practitioner_id'] ?? $kunjungan->practitioner_id,
            'practitioner_name' => $data['practitioner_name'] ?? $kunjungan->practitioner_name,
            'started_at' => $data['started_at'] ?? now(),
            'prescribed_minutes' => $data['prescribed_minutes'] ?? null,
            'access_type' => $data['access_type'] ?? null,
            'access_site' => $data['access_site'] ?? null,
            'dialyser' => $data['dialyser'] ?? null,
            'dialyser_reuse_count' => $data['dialyser_reuse_count'] ?? null,
            'machine_code' => $data['machine_code'] ?? null,
            'dialysate' => $data['dialysate'] ?? null,
            'blood_flow_ml_min' => $data['blood_flow_ml_min'] ?? null,
            'dialysate_flow_ml_min' => $data['dialysate_flow_ml_min'] ?? null,
            'dry_weight_kg' => $data['dry_weight_kg'] ?? null,
            'pre_weight_kg' => $data['pre_weight_kg'] ?? null,
            'target_ultrafiltration_l' => $data['target_ultrafiltration_l'] ?? null,
            'anticoagulant' => $data['anticoagulant'] ?? null,
            'anticoagulant_dose' => $data['anticoagulant_dose'] ?? null,
            'note' => $data['note'] ?? null,
            'status' => DialysisSession::BERJALAN,
            'recorded_by' => $actor?->id,
            'recorded_by_name' => $actor?->name,
        ]);
    }

    /**
     * Mencatat pembacaan parameter mesin.
     *
     * @throws ClinicalException
     */
    public function observe(DialysisSession $session, array $data, ?User $actor = null): DialysisObservation
    {
        if (! $session->isEditable()) {
            throw new ClinicalException(
                'Sesi ini sudah tidak berjalan, jadi pembacaan mesin tidak bisa ditambahkan lagi.'
            );
        }

        $waktu = $data['observed_at'] ?? now();

        return DialysisObservation::query()->updateOrCreate(
            ['session_id' => $session->id, 'observed_at' => $waktu],
            [
                'blood_flow_ml_min' => $data['blood_flow_ml_min'] ?? null,
                'dialysate_flow_ml_min' => $data['dialysate_flow_ml_min'] ?? null,
                'arterial_pressure_mmhg' => $data['arterial_pressure_mmhg'] ?? null,
                'venous_pressure_mmhg' => $data['venous_pressure_mmhg'] ?? null,
                'transmembrane_pressure_mmhg' => $data['transmembrane_pressure_mmhg'] ?? null,
                'ultrafiltration_rate_ml_h' => $data['ultrafiltration_rate_ml_h'] ?? null,
                'ultrafiltration_goal_l' => $data['ultrafiltration_goal_l'] ?? null,
                'action_taken' => $data['action_taken'] ?? null,
                'recorded_by' => $actor?->id,
                'recorded_by_name' => $actor?->name,
            ]
        );
    }

    /**
     * Menutup sesi yang berjalan sampai selesai.
     *
     * @throws ClinicalException
     */
    public function finish(DialysisSession $session, array $data = []): DialysisSession
    {
        if (! $session->isEditable()) {
            throw new ClinicalException('Sesi ini sudah tidak berjalan.');
        }

        $session->update([
            'ended_at' => $data['ended_at'] ?? now(),
            'post_weight_kg' => $data['post_weight_kg'] ?? $session->post_weight_kg,
            'achieved_ultrafiltration_l' => $data['achieved_ultrafiltration_l'] ?? $session->achieved_ultrafiltration_l,
            'complications' => $data['complications'] ?? $session->complications,
            'status' => DialysisSession::SELESAI,
        ]);

        return $session->refresh();
    }

    /**
     * Menghentikan sesi sebelum waktunya.
     *
     * @throws ClinicalException
     */
    public function terminate(DialysisSession $session, string $reason, array $data = []): DialysisSession
    {
        if (! $session->isEditable()) {
            throw new ClinicalException('Sesi ini sudah tidak berjalan.');
        }

        $alasan = trim($reason);

        if ($alasan === '') {
            throw new ClinicalException(
                'Alasan penghentian wajib diisi. Sesi yang berhenti di tengah justru yang ditinjau saat '
                .'kecukupan dialisis seorang pasien meleset berulang kali.'
            );
        }

        $session->update([
            'ended_at' => $data['ended_at'] ?? now(),
            'post_weight_kg' => $data['post_weight_kg'] ?? $session->post_weight_kg,
            'achieved_ultrafiltration_l' => $data['achieved_ultrafiltration_l'] ?? $session->achieved_ultrafiltration_l,
            'status' => DialysisSession::DIHENTIKAN,
            'termination_reason' => $alasan,
        ]);

        return $session->refresh();
    }

    // ---------------------------------------------------------------- baca

    public function forRegistration(int $registrationId): ?DialysisSession
    {
        return DialysisSession::query()
            ->where('registration_id', $registrationId)
            ->where('status', '<>', DialysisSession::DIBATALKAN)
            ->with('observations')
            ->first();
    }

    /**
     * Sesi yang durasinya tidak tercapai sepanjang satu periode.
     *
     * Inilah gunanya durasi dihitung: daftar ini tidak bisa disusun dari
     * kolom `lama` yang diketik.
     */
    public function shortSessions(string $from, string $until): Collection
    {
        return DialysisSession::query()
            ->whereBetween('started_at', [$from, $until])
            ->whereIn('status', [DialysisSession::SELESAI, DialysisSession::DIHENTIKAN])
            ->whereNotNull('prescribed_minutes')
            ->get()
            ->filter(fn (DialysisSession $s) => $s->isTimeShortfall() === true)
            ->values();
    }

    /** Pasien dialisis yang serologinya sudah kedaluwarsa atau belum ada. */
    public function serologyDue(): Collection
    {
        return DialysisSession::query()
            ->select('patient_id', 'patient_mrn', 'patient_name')
            ->distinct()
            ->where('status', '<>', DialysisSession::DIBATALKAN)
            ->get()
            ->filter(function ($sesi) {
                $serologi = $this->latestSerology($sesi->patient_id);

                return $serologi === null || ! $serologi->isValid();
            })
            ->values();
    }

    // ------------------------------------------------------------ internal

    /**
     * Sesi tidak dimulai tanpa serologi yang masih berlaku, KECUALI ada
     * alasan tertulis.
     *
     * Bukan larangan mutlak: pasien yang butuh dialisis segera tidak
     * boleh ditunda menunggu laboratorium, dan sistem yang melarangnya
     * akan dilewati dengan mencatat serologi karangan — yang jauh lebih
     * berbahaya daripada mencatat bahwa serologinya belum ada.
     *
     * @throws ClinicalException
     */
    private function assertSerologyKnown(int $patientId, array $data): void
    {
        if (filled($data['serology_override_reason'] ?? null)) {
            return;
        }

        $serologi = $this->latestSerology($patientId);

        if ($serologi !== null && $serologi->isValid()) {
            return;
        }

        throw new ClinicalException(
            $serologi === null
                ? 'Pasien ini belum punya hasil serologi (HBsAg, anti-HCV, anti-HIV). Serologi menentukan '
                    .'apakah ia harus memakai mesin terpisah. Bila dialisis tetap perlu dimulai sekarang, '
                    .'sebutkan alasannya lewat serology_override_reason supaya keputusannya tercatat.'
                : sprintf(
                    'Serologi terakhir pasien ini (%s) sudah lewat masa berlaku %d bulan pada %s. '
                    .'Periksa ulang, atau sebutkan alasan memulai dialisis tanpa itu.',
                    $serologi->tested_on->toDateString(),
                    DialysisSerology::MASA_BERLAKU_BULAN,
                    $serologi->expiresOn()->toDateString(),
                )
        );
    }

    /**
     * @return array{mrn: string, name: string}
     *
     * @throws ClinicalException
     */
    private function patientIdentity(int $patientId): array
    {
        $kunjungan = DB::table(self::REGISTRASI)
            ->where('patient_id', $patientId)
            ->orderByDesc('service_date')
            ->first();

        if ($kunjungan !== null) {
            return ['mrn' => $kunjungan->patient_mrn, 'name' => $kunjungan->patient_name];
        }

        $sesi = DialysisSession::query()->where('patient_id', $patientId)->first();

        if ($sesi !== null) {
            return ['mrn' => $sesi->patient_mrn, 'name' => $sesi->patient_name];
        }

        throw new ClinicalException(
            'Pasien ini belum punya kunjungan maupun sesi dialisis, jadi identitasnya belum bisa disalin.'
        );
    }
}
