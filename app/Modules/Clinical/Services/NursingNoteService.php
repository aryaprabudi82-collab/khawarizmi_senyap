<?php

namespace App\Modules\Clinical\Services;

use App\Modules\Clinical\Models\NursingDiagnosis;
use App\Modules\Clinical\Models\NursingNote;
use App\Modules\Clinical\Models\PatientNote;
use App\Modules\Platform\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Catatan keperawatan & catatan pasien (domain M item E) — 3 kode:
 * catatan_keperawatan_ralan, catatan_keperawatan_ranap, catatan_pasien.
 *
 * MELENGKAPI PROSES KEPERAWATAN YANG DIMULAI DI ITEM B. Pengkajian,
 * diagnosis, dan perencanaan dibangun di sana; implementasi dan evaluasi
 * tempatnya di sini — persis seperti pemisahan di Khanza, yang menaruh
 * catatan_keperawatan_* terpisah dari penilaian_awal_keperawatan_*.
 *
 * TAUTAN KE DIAGNOSIS BERSIFAT OPSIONAL, dan itu disengaja. Tidak setiap
 * catatan perawat menindaklanjuti satu masalah tertentu: "pasien mengeluh
 * pusing saat bangun" adalah pengamatan, bukan pelaksanaan rencana.
 * Mewajibkan tautannya akan memaksa perawat memilih masalah yang tidak
 * nyata supaya catatannya bisa disimpan — dan catatan yang dipaksa
 * berkaitan lebih menyesatkan daripada catatan yang berdiri sendiri.
 *
 * TAPI KALAU DITAUTKAN, DIAGNOSISNYA HARUS MILIK KUNJUNGAN YANG SAMA.
 * Catatan yang menunjuk masalah pasien lain adalah kekeliruan yang tidak
 * akan pernah terlihat dari layar mana pun.
 *
 * CATATAN TIDAK BISA DIUBAH SETELAH DITULIS. Rekam medis keperawatan
 * adalah pernyataan seseorang pada satu waktu; koreksi ditulis sebagai
 * catatan baru yang menyebut apa yang diralat, bukan dengan menimpa yang
 * lama. Aturan yang sama seperti asesmen final.
 *
 * CATATAN PASIEN BERLAKU LINTAS KUNJUNGAN. Isinya hal yang tetap benar di
 * luar satu kunjungan; menempelkannya pada kunjungan membuatnya hilang
 * dari pandangan pada kunjungan berikutnya, justru saat ia paling
 * dibutuhkan.
 */
class NursingNoteService
{
    /**
     * Mencatat satu catatan keperawatan.
     *
     * @throws ClinicalException
     */
    public function record(
        int $registrationId,
        string $note,
        string $kind = NursingNote::IMPLEMENTASI,
        ?NursingDiagnosis $diagnosis = null,
        ?Carbon $recordedAt = null,
        ?User $actor = null,
    ): NursingNote {
        $isi = trim($note);

        if ($isi === '') {
            throw new ClinicalException('Isi catatan keperawatan wajib diisi.');
        }

        if (! in_array($kind, NursingNote::JENIS, true)) {
            throw new ClinicalException("Jenis catatan '{$kind}' tidak dikenal.");
        }

        $kunjungan = DB::table('encounter.v_registration_summary')->where('id', $registrationId)->first()
            ?? throw new ClinicalException('Kunjungan tidak ditemukan atau sudah dibatalkan.');

        // Kalau ditautkan, diagnosisnya harus milik kunjungan yang sama.
        if ($diagnosis !== null && (int) $diagnosis->registration_id !== $registrationId) {
            throw new ClinicalException(
                'Diagnosis keperawatan yang ditunjuk bukan milik kunjungan ini.'
            );
        }

        return NursingNote::query()->create([
            'registration_id' => $registrationId,
            'patient_id' => $kunjungan->patient_id,
            'nursing_diagnosis_id' => $diagnosis?->id,
            'kind' => $kind,
            'note' => $isi,
            'recorded_by' => $actor?->id,
            'recorded_by_name' => $actor?->name,
            'recorded_at' => $recordedAt ?? now(),
        ]);
    }

    /**
     * Meralat catatan dengan catatan BARU, bukan dengan menimpa.
     *
     * @throws ClinicalException
     */
    public function amend(NursingNote $original, string $correction, ?User $actor = null): NursingNote
    {
        $isi = trim($correction);

        if ($isi === '') {
            throw new ClinicalException('Isi ralat wajib diisi.');
        }

        return $this->record(
            $original->registration_id,
            'Ralat atas catatan ' . $original->recorded_at->format('d-m-Y H:i')
                . ' oleh ' . ($original->recorded_by_name ?? 'petugas') . ': ' . $isi,
            NursingNote::EVALUASI,
            null,
            null,
            $actor,
        );
    }

    /** Catatan satu kunjungan, terbaru lebih dulu. */
    public function forRegistration(int $registrationId, ?string $kind = null, int $limit = 200): Collection
    {
        return NursingNote::query()
            ->where('registration_id', $registrationId)
            ->when($kind, fn ($q) => $q->where('kind', $kind))
            ->orderByDesc('recorded_at')
            ->orderByDesc('id')
            ->limit($limit)
            ->get();
    }

    /**
     * Catatan yang menindaklanjuti satu masalah keperawatan.
     *
     * Inilah yang menutup lingkaran proses keperawatan: masalah yang
     * ditegakkan, rencana yang disusun, dan apa yang benar-benar
     * dikerjakan atasnya.
     */
    public function forDiagnosis(NursingDiagnosis $diagnosis): Collection
    {
        return NursingNote::query()
            ->where('nursing_diagnosis_id', $diagnosis->id)
            ->orderBy('recorded_at')
            ->get();
    }

    // ---------------------------------------------------------- catatan pasien

    /**
     * @throws ClinicalException
     */
    public function recordPatientNote(int $patientId, string $mrn, string $note, bool $isAlert = false, ?User $actor = null): PatientNote
    {
        $isi = trim($note);

        if ($isi === '') {
            throw new ClinicalException('Isi catatan pasien wajib diisi.');
        }

        return PatientNote::query()->create([
            'patient_id' => $patientId,
            'patient_mrn' => $mrn,
            'note' => $isi,
            'is_alert' => $isAlert,
            'recorded_by' => $actor?->id,
            'recorded_by_name' => $actor?->name,
        ]);
    }

    /**
     * Catatan pasien yang harus menonjol lebih dulu.
     *
     * Yang ditandai penting muncul di atas, karena catatan penting yang
     * terkubur di antara catatan biasa sama saja dengan tidak dicatat.
     */
    public function patientNotes(int $patientId): Collection
    {
        return PatientNote::query()
            ->where('patient_id', $patientId)
            ->orderByDesc('is_alert')
            ->orderByDesc('created_at')
            ->get();
    }

    public function removePatientNote(PatientNote $note): void
    {
        $note->delete();
    }
}
