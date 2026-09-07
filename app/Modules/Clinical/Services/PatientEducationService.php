<?php

namespace App\Modules\Clinical\Services;

use App\Modules\Clinical\Models\EducationSession;
use App\Modules\Clinical\Models\LearningAssessment;
use App\Modules\Platform\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Edukasi pasien & keluarga (domain M item Q).
 *
 * LIMA ATURAN.
 *
 * 1. PERTANYAAN KEYAKINAN TIDAK DIWAJIBKAN. Khanza memasang tiga
 *    pertanyaan keyakinan sebagai NOT NULL dengan kosakata yang sangat
 *    sempit — penyakitnya ujian atau kutukan, tanpa pilihan ketiga yang
 *    masuk akal dan tanpa "belum ditanyakan". Yang dihasilkan hanya
 *    isian asal-asalan atau label yang dipaksakan pada keyakinan orang.
 *
 * 2. CARA DAN HAMBATAN BELAJAR ADALAH DAFTAR. Pasien yang paling perlu
 *    diperhatikan justru yang punya beberapa hambatan sekaligus, dan
 *    memaksa memilih satu membuang hambatan yang lain — lalu edukasinya
 *    gagal karena hambatan yang dibuang itu.
 *
 * 3. PENGULANGAN MENUNJUK APA YANG DIULANG, sehingga materi yang gagal
 *    diverifikasi bisa ditagih sampai benar-benar diulang.
 *
 * 4. LAMA EDUKASI DIHITUNG dari jam mulai dan selesai.
 *
 * 5. FOTO BUKTI MEMAKAI MEKANISME BERKAS REKAM MEDIS dari item L,
 *    bukan tabel berkas keempat tanpa pengunggah maupun waktunya.
 */
class PatientEducationService
{
    private const REGISTRASI = 'encounter.v_registration_summary';

    /**
     * Mencatat pengkajian kebutuhan belajar.
     *
     * @throws ClinicalException
     */
    public function assessLearningNeeds(int $registrationId, array $data, ?User $actor = null): LearningAssessment
    {
        $kunjungan = DB::table(self::REGISTRASI)->where('id', $registrationId)->first()
            ?? throw new ClinicalException('Kunjungan tidak ditemukan atau sudah dibatalkan.');

        $penilai = trim($data['assessed_by_name'] ?? $actor?->name ?? '');

        if ($penilai === '') {
            throw new ClinicalException('Nama petugas yang mengkaji wajib dicatat.');
        }

        $this->assertVocabulary($data, 'illness_belief', LearningAssessment::KEYAKINAN_PENYAKIT, 'Keyakinan tentang penyakit');
        $this->assertVocabulary($data, 'decision_maker', LearningAssessment::PENGAMBIL_KEPUTUSAN, 'Pengambil keputusan');
        $this->assertVocabulary($data, 'therapy_belief', LearningAssessment::KEYAKINAN_TERAPI, 'Keyakinan terhadap terapi');

        $isi = array_intersect_key($data, array_flip([
            'speech', 'speech_note', 'daily_language', 'needs_interpreter', 'interpreter_language',
            'uses_sign_language', 'barrier_note', 'learning_ability', 'learning_ability_note',
            'illness_belief', 'illness_belief_note', 'decision_maker', 'decision_maker_note',
            'therapy_belief', 'therapy_belief_note', 'spiritual_need',
        ]));

        return LearningAssessment::query()->updateOrCreate(
            ['registration_id' => $registrationId],
            $isi + [
                'patient_id' => $kunjungan->patient_id,
                'registration_number' => $kunjungan->registration_number,
                'patient_mrn' => $kunjungan->patient_mrn,
                'patient_name' => $kunjungan->patient_name,
                'assessed_at' => $data['assessed_at'] ?? now(),
                'learning_preferences' => $this->validList(
                    $data['learning_preferences'] ?? [],
                    LearningAssessment::CARA_BELAJAR,
                    'Cara belajar',
                ),
                'learning_barriers' => $this->validList(
                    $data['learning_barriers'] ?? [],
                    LearningAssessment::HAMBATAN,
                    'Hambatan belajar',
                ),
                'assessed_by' => $actor?->id,
                'assessed_by_name' => $penilai,
            ]
        );
    }

    public function learningNeedsFor(int $registrationId): ?LearningAssessment
    {
        return LearningAssessment::query()->where('registration_id', $registrationId)->first();
    }

    /**
     * Mencatat pelaksanaan edukasi.
     *
     * @throws ClinicalException
     */
    public function educate(int $registrationId, array $data, ?User $actor = null): EducationSession
    {
        $kunjungan = DB::table(self::REGISTRASI)->where('id', $registrationId)->first()
            ?? throw new ClinicalException('Kunjungan tidak ditemukan atau sudah dibatalkan.');

        $topik = trim($data['topic'] ?? '');
        $materi = trim($data['material'] ?? '');

        if ($topik === '' || $materi === '') {
            throw new ClinicalException(
                'Topik dan materi edukasi keduanya wajib diisi. Catatan "sudah diberi edukasi" tanpa '
                .'menyebut materinya tidak bisa diulang maupun diverifikasi.'
            );
        }

        $penerima = $data['given_to'] ?? '';

        if (! array_key_exists($penerima, EducationSession::PENERIMA)) {
            throw new ClinicalException(
                "Penerima edukasi '{$penerima}' tidak dikenali. Pilihannya: "
                .implode(', ', array_keys(EducationSession::PENERIMA)).'.'
            );
        }

        $metode = $data['method'] ?? '';

        if (! array_key_exists($metode, EducationSession::METODE)) {
            throw new ClinicalException("Metode edukasi '{$metode}' tidak dikenali.");
        }

        $verifikasi = $data['verification'] ?? null;

        if ($verifikasi !== null && ! array_key_exists($verifikasi, EducationSession::VERIFIKASI)) {
            throw new ClinicalException("Hasil verifikasi '{$verifikasi}' tidak dikenali.");
        }

        $pemberi = trim($data['educator_name'] ?? $actor?->name ?? '');

        if ($pemberi === '') {
            throw new ClinicalException('Nama pemberi edukasi wajib dicatat.');
        }

        $diulang = $this->resolveRepeatedSession($data['repeats_session_id'] ?? null, $registrationId);

        return EducationSession::query()->create([
            'registration_id' => $registrationId,
            'patient_id' => $kunjungan->patient_id,
            'registration_number' => $kunjungan->registration_number,
            'patient_mrn' => $kunjungan->patient_mrn,
            'patient_name' => $kunjungan->patient_name,
            'topic' => $topik,
            'material' => $materi,
            'given_to' => $penerima,
            'given_to_note' => $data['given_to_note'] ?? null,
            'recipient_name' => $data['recipient_name'] ?? null,
            'recipient_relation' => $data['recipient_relation'] ?? null,
            'method' => $metode,
            'started_at' => $data['started_at'] ?? now(),
            'ended_at' => $data['ended_at'] ?? null,
            'verification' => $verifikasi,
            'verification_note' => $data['verification_note'] ?? null,
            'repeats_session_id' => $diulang?->id,
            'educator_id' => $actor?->id,
            'educator_name' => $pemberi,
            'educator_role' => $data['educator_role'] ?? null,
        ]);
    }

    /**
     * Menutup sesi edukasi berikut hasil verifikasinya.
     *
     * @throws ClinicalException
     */
    public function verify(EducationSession $session, string $verification, array $data = []): EducationSession
    {
        if (! array_key_exists($verification, EducationSession::VERIFIKASI)) {
            throw new ClinicalException("Hasil verifikasi '{$verification}' tidak dikenali.");
        }

        $session->update([
            'ended_at' => $data['ended_at'] ?? $session->ended_at ?? now(),
            'verification' => $verification,
            'verification_note' => $data['verification_note'] ?? $session->verification_note,
        ]);

        return $session->refresh();
    }

    // ---------------------------------------------------------------- baca

    public function sessionsFor(int $registrationId): Collection
    {
        return EducationSession::query()
            ->where('registration_id', $registrationId)
            ->with(['repeatsSession', 'repeatedBy'])
            ->orderBy('started_at')
            ->get();
    }

    /**
     * Edukasi yang perlu diulang dan belum ada pengulangannya.
     *
     * Inilah kegunaan menautkan pengulangan ke apa yang diulang: daftar
     * ini tidak bisa disusun dari penanda "Awal/Ulang" milik Khanza.
     */
    public function outstandingRepeats(?int $registrationId = null): Collection
    {
        return EducationSession::query()
            ->whereIn('verification', EducationSession::PERLU_DIULANG)
            ->when($registrationId, fn ($q) => $q->where('registration_id', $registrationId))
            ->whereDoesntHave('repeatedBy')
            ->orderBy('started_at')
            ->get();
    }

    // ------------------------------------------------------------ internal

    /**
     * @throws ClinicalException
     */
    private function resolveRepeatedSession(?int $sessionId, int $registrationId): ?EducationSession
    {
        if ($sessionId === null) {
            return null;
        }

        $lama = EducationSession::query()->find($sessionId)
            ?? throw new ClinicalException('Edukasi yang diulang tidak ditemukan.');

        if ($lama->registration_id !== $registrationId) {
            throw new ClinicalException(
                'Edukasi yang diulang milik kunjungan lain. Pengulangan yang menunjuk edukasi pasien lain '
                .'akan membuat materi yang masih menggantung terbaca sudah selesai.'
            );
        }

        if (! $lama->needsRepeat()) {
            throw new ClinicalException(
                'Edukasi yang ditunjuk sudah diverifikasi mengerti, jadi tidak perlu diulang. Catat '
                .'edukasi baru bila memang ada materi berikutnya.'
            );
        }

        if ($lama->repeatedBy()->exists()) {
            throw new ClinicalException(
                'Edukasi itu sudah pernah diulang. Bila pengulangannya pun belum dimengerti, ulangi '
                .'pengulangannya — supaya rantainya tetap lurus dan bisa dibaca.'
            );
        }

        return $lama;
    }

    /**
     * @param  array<string, string>  $kosakata
     *
     * @throws ClinicalException
     */
    private function assertVocabulary(array $data, string $key, array $kosakata, string $label): void
    {
        $nilai = $data[$key] ?? null;

        // NULL sah dan disengaja — pertanyaan keyakinan tidak diwajibkan.
        if ($nilai === null) {
            return;
        }

        if (! array_key_exists($nilai, $kosakata)) {
            throw new ClinicalException(
                "{$label} '{$nilai}' tidak dikenali. Pilihannya: ".implode(', ', array_keys($kosakata))
                .' — dan boleh juga dikosongkan bila belum ditanyakan.'
            );
        }
    }

    /**
     * @param  mixed  $nilai
     * @param  array<string, string>  $kosakata
     * @return array<int, string>
     *
     * @throws ClinicalException
     */
    private function validList($nilai, array $kosakata, string $label): array
    {
        if (! is_array($nilai)) {
            throw new ClinicalException("{$label} harus berupa daftar.");
        }

        $bersih = array_values(array_unique(array_filter($nilai, 'is_string')));
        $asing = array_diff($bersih, array_keys($kosakata));

        if ($asing !== []) {
            throw new ClinicalException(
                "{$label} tidak dikenali: ".implode(', ', $asing)
                .'. Pilihannya: '.implode(', ', array_keys($kosakata)).'.'
            );
        }

        return $bersih;
    }
}
