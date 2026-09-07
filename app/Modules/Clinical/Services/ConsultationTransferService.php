<?php

namespace App\Modules\Clinical\Services;

use App\Modules\Clinical\Models\Diagnosis;
use App\Modules\Clinical\Models\MedicalConsultation;
use App\Modules\Clinical\Models\PatientTransfer;
use App\Modules\Platform\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Konsultasi medik & transfer antar ruang (domain M item N).
 *
 * LIMA ATURAN.
 *
 * 1. JAWABAN KONSULTASI WAJIB MENYEBUT PENJAWABNYA.
 *    jawaban_konsultasi_medik Khanza tidak menyediakan kolomnya; yang
 *    tercatat hanya siapa yang DITANYA. Nasihat klinis yang dijalankan
 *    dokter lain harus bisa ditanyakan kembali kepada penulisnya.
 *
 * 2. YANG MENJAWAB BOLEH BERBEDA DARI YANG DITANYA, dan itu dicatat apa
 *    adanya — konsulen jaga memang sering yang menjawab. Yang salah
 *    bukan kejadiannya, melainkan tidak bisa membedakannya.
 *
 * 3. ALAT YANG MENYERTAI PASIEN ADALAH DAFTAR. Enum Khanza cuma memuat
 *    satu, padahal ruangan penerima butuh daftar lengkapnya.
 *
 * 4. ASAL DAN TUJUAN RUANG DISALIN DARI ADMISI, bukan diketik bebas;
 *    diagnosis dibekukan dari rekam medis, bukan diketik untuk ketiga
 *    kalinya.
 *
 * 5. PENYERAH DAN PENERIMA KEDUANYA WAJIB. Transfer adalah serah
 *    terima; yang tercatat sepihak bukan serah terima.
 */
class ConsultationTransferService
{
    private const REGISTRASI = 'encounter.v_registration_summary';

    private const ADMISI = 'inpatient.v_admission_summary';

    // ------------------------------------------------------- konsultasi

    /**
     * Mengajukan konsultasi.
     *
     * @throws ClinicalException
     */
    public function request(int $registrationId, array $data, ?User $actor = null): MedicalConsultation
    {
        $kunjungan = DB::table(self::REGISTRASI)->where('id', $registrationId)->first()
            ?? throw new ClinicalException('Kunjungan tidak ditemukan atau sudah dibatalkan.');

        $jenis = $data['kind'] ?? '';

        if (! array_key_exists($jenis, MedicalConsultation::JENIS)) {
            throw new ClinicalException(
                "Jenis permintaan '{$jenis}' tidak dikenali. Pilihannya: "
                .implode(', ', array_keys(MedicalConsultation::JENIS)).'.'
            );
        }

        $kesegeraan = $data['urgency'] ?? 'biasa';

        if (! array_key_exists($kesegeraan, MedicalConsultation::KESEGERAAN)) {
            throw new ClinicalException("Tingkat kesegeraan '{$kesegeraan}' tidak dikenali.");
        }

        $pertanyaan = trim($data['question'] ?? '');

        if ($pertanyaan === '') {
            throw new ClinicalException(
                'Uraian konsultasi wajib diisi. Konsultasi tanpa pertanyaan memaksa konsulen menebak '
                .'apa yang sebenarnya ingin diketahui.'
            );
        }

        $peminta = trim($data['requesting_practitioner_name'] ?? $kunjungan->practitioner_name ?? '');

        if ($peminta === '') {
            throw new ClinicalException('Nama dokter yang meminta konsultasi wajib disebut.');
        }

        return MedicalConsultation::query()->create([
            'request_number' => $this->allocateNumber(),
            'registration_id' => $registrationId,
            'patient_id' => $kunjungan->patient_id,
            'registration_number' => $kunjungan->registration_number,
            'patient_mrn' => $kunjungan->patient_mrn,
            'patient_name' => $kunjungan->patient_name,
            'requested_at' => $data['requested_at'] ?? now(),
            'kind' => $jenis,
            'urgency' => $kesegeraan,
            'requesting_practitioner_id' => $data['requesting_practitioner_id'] ?? $kunjungan->practitioner_id,
            'requesting_practitioner_name' => $peminta,
            'consulted_practitioner_id' => $data['consulted_practitioner_id'] ?? null,
            'consulted_practitioner_name' => $data['consulted_practitioner_name'] ?? null,
            'consulted_specialty' => $data['consulted_specialty'] ?? null,
            'working_diagnosis' => $data['working_diagnosis'] ?? null,
            'question' => $pertanyaan,
            'status' => MedicalConsultation::TERBUKA,
            'recorded_by' => $actor?->id,
            'recorded_by_name' => $actor?->name,
        ]);
    }

    /**
     * Menjawab konsultasi.
     *
     * @throws ClinicalException
     */
    public function answer(
        MedicalConsultation $consultation,
        string $answer,
        array $data = [],
        ?User $actor = null,
    ): MedicalConsultation {
        if ($consultation->status === MedicalConsultation::DIJAWAB) {
            throw new ClinicalException(
                'Konsultasi ini sudah dijawab. Jawaban tambahan dicatat sebagai konsultasi evaluasi, '
                .'bukan dengan menimpa jawaban yang sudah dibaca dokter peminta.'
            );
        }

        if ($consultation->status === MedicalConsultation::DIBATALKAN) {
            throw new ClinicalException('Konsultasi yang dibatalkan tidak bisa dijawab.');
        }

        $isi = trim($answer);

        if ($isi === '') {
            throw new ClinicalException('Uraian jawaban wajib diisi.');
        }

        $penjawab = trim($data['answering_practitioner_name'] ?? $actor?->name ?? '');

        if ($penjawab === '') {
            throw new ClinicalException(
                'Nama dokter yang menjawab wajib disebut — dan itu belum tentu dokter yang ditanya. '
                .'Nasihat klinis yang dijalankan dokter lain harus bisa ditanyakan kembali kepada '
                .'penulisnya.'
            );
        }

        $consultation->update([
            'answered_at' => $data['answered_at'] ?? now(),
            'answering_practitioner_id' => $data['answering_practitioner_id'] ?? $actor?->id,
            'answering_practitioner_name' => $penjawab,
            'answer_diagnosis' => $data['answer_diagnosis'] ?? null,
            'answer' => $isi,
            'status' => MedicalConsultation::DIJAWAB,
        ]);

        return $consultation->refresh();
    }

    /**
     * @throws ClinicalException
     */
    public function cancelConsultation(MedicalConsultation $consultation, string $reason): MedicalConsultation
    {
        $alasan = trim($reason);

        if ($alasan === '') {
            throw new ClinicalException('Alasan pembatalan wajib diisi.');
        }

        if ($consultation->status === MedicalConsultation::DIBATALKAN) {
            throw new ClinicalException('Konsultasi ini sudah dibatalkan.');
        }

        $consultation->update([
            'status' => MedicalConsultation::DIBATALKAN,
            'cancellation_reason' => $alasan,
        ]);

        return $consultation->refresh();
    }

    /**
     * Konsultasi yang belum dijawab dan sudah lewat tenggat
     * kesegeraannya.
     *
     * Tenggatnya DIHITUNG dari waktu permintaan; yang cito ditagih lebih
     * cepat daripada yang rutin, dan itulah gunanya membedakan
     * kesegeraan yang tidak dibedakan Khanza.
     */
    public function overdueConsultations(): Collection
    {
        return MedicalConsultation::query()
            ->where('status', MedicalConsultation::TERBUKA)
            ->orderBy('requested_at')
            ->get()
            ->filter(fn (MedicalConsultation $k) => $k->isOverdue())
            ->values();
    }

    public function consultationsFor(int $registrationId): Collection
    {
        return MedicalConsultation::query()
            ->where('registration_id', $registrationId)
            ->where('status', '<>', MedicalConsultation::DIBATALKAN)
            ->orderBy('requested_at')
            ->get();
    }

    // ---------------------------------------------------------- transfer

    /**
     * Mencatat serah terima pasien antar ruang.
     *
     * @throws ClinicalException
     */
    public function transfer(int $registrationId, array $data, ?User $actor = null): PatientTransfer
    {
        $kunjungan = DB::table(self::REGISTRASI)->where('id', $registrationId)->first()
            ?? throw new ClinicalException('Kunjungan tidak ditemukan atau sudah dibatalkan.');

        $indikasi = $data['indication'] ?? '';

        if (! array_key_exists($indikasi, PatientTransfer::INDIKASI)) {
            throw new ClinicalException(
                "Indikasi pemindahan '{$indikasi}' tidak dikenali. Pilihannya: "
                .implode(', ', array_keys(PatientTransfer::INDIKASI)).'.'
            );
        }

        $keterangan = trim($data['indication_note'] ?? '');

        if ($indikasi === PatientTransfer::LAIN_LAIN && $keterangan === '') {
            throw new ClinicalException(
                'Keterangan wajib diisi saat indikasinya "lain-lain". Indikasi pemindahan justru yang '
                .'ditinjau saat mutu perawatan dipertanyakan, dan "lain-lain" tanpa keterangan tidak '
                .'menjelaskan apa pun.'
            );
        }

        $cara = $data['transport_method'] ?? null;

        if ($cara !== null && ! array_key_exists($cara, PatientTransfer::CARA_ANGKUT)) {
            throw new ClinicalException("Cara pemindahan '{$cara}' tidak dikenali.");
        }

        $penyerah = trim($data['handed_over_by_name'] ?? $actor?->name ?? '');
        $penerima = trim($data['received_by_name'] ?? '');

        if ($penyerah === '' || $penerima === '') {
            throw new ClinicalException(
                'Nama petugas yang menyerahkan DAN yang menerima keduanya wajib diisi. Transfer adalah '
                .'serah terima; yang tercatat sepihak bukan serah terima melainkan kepindahan yang '
                .'kebetulan diketahui satu orang.'
            );
        }

        $peralatan = $this->validEquipment($data['accompanying_equipment'] ?? []);

        $admisi = DB::table(self::ADMISI)
            ->where('registration_id', $registrationId)
            ->orderByDesc('admission_id')
            ->first();

        return PatientTransfer::query()->create([
            'registration_id' => $registrationId,
            'patient_id' => $kunjungan->patient_id,
            'admission_id' => $admisi?->admission_id,
            'registration_number' => $kunjungan->registration_number,
            'patient_mrn' => $kunjungan->patient_mrn,
            'patient_name' => $kunjungan->patient_name,
            'transferred_at' => $data['transferred_at'] ?? now(),
            // Disalin dari kontrak admisi, tidak diketik.
            'from_room' => $data['from_room'] ?? $admisi?->room_number,
            'from_unit_name' => $data['from_unit_name'] ?? $admisi?->room_unit_name,
            'to_room' => $data['to_room'] ?? null,
            'to_unit_name' => $data['to_unit_name'] ?? null,
            // Dibekukan dari rekam medis, tidak diketik ulang.
            'diagnoses' => $this->freezeDiagnoses($registrationId),
            'indication' => $indikasi,
            'indication_note' => $keterangan !== '' ? $keterangan : null,
            'procedures_done' => $data['procedures_done'] ?? null,
            'medication_given' => $data['medication_given'] ?? null,
            'transport_method' => $cara,
            'accompanying_equipment' => $peralatan,
            'equipment_note' => $data['equipment_note'] ?? null,
            'handed_over_by' => $actor?->id,
            'handed_over_by_name' => $penyerah,
            'received_by' => $data['received_by'] ?? null,
            'received_by_name' => $penerima,
            'consent_given_by' => $data['consent_given_by'] ?? null,
            'consent_relation' => $data['consent_relation'] ?? null,
            'note' => $data['note'] ?? null,
            'recorded_by' => $actor?->id,
            'recorded_by_name' => $actor?->name,
        ]);
    }

    public function transfersFor(int $registrationId): Collection
    {
        return PatientTransfer::query()
            ->where('registration_id', $registrationId)
            ->orderBy('transferred_at')
            ->get();
    }

    // ------------------------------------------------------------ internal

    /**
     * @param  mixed  $nilai
     * @return array<int, string>
     *
     * @throws ClinicalException
     */
    private function validEquipment($nilai): array
    {
        if (! is_array($nilai)) {
            throw new ClinicalException('Peralatan yang menyertai harus berupa daftar.');
        }

        $bersih = array_values(array_unique(array_filter($nilai, 'is_string')));
        $asing = array_diff($bersih, array_keys(PatientTransfer::PERALATAN));

        if ($asing !== []) {
            throw new ClinicalException(
                'Peralatan tidak dikenali: '.implode(', ', $asing)
                .'. Pilihannya: '.implode(', ', array_keys(PatientTransfer::PERALATAN)).'.'
            );
        }

        return $bersih;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function freezeDiagnoses(int $registrationId): array
    {
        return Diagnosis::query()
            ->where('registration_id', $registrationId)
            ->orderByRaw('CASE "rank" WHEN \'utama\' THEN 1 WHEN \'komplikasi\' THEN 2 ELSE 3 END')
            ->orderBy('id')
            ->get()
            ->map(fn ($d) => [
                'code' => $d->code,
                'display' => $d->display,
                'rank' => $d->rank,
            ])
            ->all();
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

        return 'KM'.$prefix.'-'.str_pad((string) $row->last_number, 4, '0', STR_PAD_LEFT);
    }
}
