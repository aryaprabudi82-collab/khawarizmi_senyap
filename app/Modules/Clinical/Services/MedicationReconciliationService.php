<?php

namespace App\Modules\Clinical\Services;

use App\Modules\Clinical\Models\Allergy;
use App\Modules\Clinical\Models\MedicationReconciliation;
use App\Modules\Clinical\Models\MedicationReconciliationItem;
use App\Modules\Platform\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Rekonsiliasi obat (domain M item I).
 *
 * EMPAT ATURAN.
 *
 * 1. ALERGI DITULIS KE DAFTAR ALERGI PASIEN, BUKAN KE SINI. Khanza
 *    memberi rekonsiliasi_obat tiga kolom alerginya sendiri
 *    (alergi_obat, manifestasi_alergi, dampak_alergi) yang persis
 *    duplikat clinical.allergies. Akibatnya alergi yang ditemukan saat
 *    wawancara obat TIDAK terbaca oleh telaah resep, karena telaah
 *    membaca daftar yang satunya lagi. Di sini wawancara justru
 *    menambah ke daftar pasien; yang dibekukan hanya salinan keadaan
 *    saat wawancara, sebagai dokumen.
 *
 * 2. SETIAP OBAT HARUS PUNYA TINDAK LANJUT SEBELUM DIFINALKAN. Obat
 *    yang didaftar tapi tidak diputuskan nasibnya adalah pertanyaan
 *    yang dibiarkan menggantung: perawat di ruangan tidak tahu apakah
 *    obat itu boleh diteruskan.
 *
 * 3. RANTAI KONFIRMASI BERURUTAN. Diterima farmasi, dikonfirmasi
 *    apoteker, diserahkan ke pasien — dalam urutan itu. Khanza
 *    menyediakan ketiga waktunya tanpa menjaga urutannya, sehingga
 *    obat bisa tercatat diserahkan sebelum ada yang mengonfirmasinya.
 *
 * 4. SATU KESEMPATAN SATU REKONSILIASI, tapi satu kunjungan boleh
 *    beberapa: saat masuk, saat pindah ruang, dan saat pulang adalah
 *    tiga wawancara tentang daftar obat yang berbeda.
 */
class MedicationReconciliationService
{
    private const REGISTRASI = 'encounter.v_registration_summary';

    public function __construct(private readonly ClinicalRecordService $records) {}

    /**
     * @throws ClinicalException
     */
    public function open(
        int $registrationId,
        string $occasion,
        array $data = [],
        ?User $actor = null,
    ): MedicationReconciliation {
        if (! array_key_exists($occasion, MedicationReconciliation::KESEMPATAN)) {
            throw new ClinicalException(
                "Kesempatan rekonsiliasi '{$occasion}' tidak dikenali. Pilihannya: "
                .implode(', ', array_keys(MedicationReconciliation::KESEMPATAN)).'.'
            );
        }

        $kunjungan = DB::table(self::REGISTRASI)->where('id', $registrationId)->first()
            ?? throw new ClinicalException('Kunjungan tidak ditemukan atau sudah dibatalkan.');

        $ada = MedicationReconciliation::query()
            ->where('registration_id', $registrationId)
            ->where('occasion', $occasion)
            ->where('status', '<>', MedicationReconciliation::DIBATALKAN)
            ->first();

        if ($ada !== null) {
            return $ada;
        }

        return MedicationReconciliation::query()->create([
            'registration_id' => $registrationId,
            'patient_id' => $kunjungan->patient_id,
            'registration_number' => $kunjungan->registration_number,
            'patient_mrn' => $kunjungan->patient_mrn,
            'patient_name' => $kunjungan->patient_name,
            'occasion' => $occasion,
            'interviewed_at' => $data['interviewed_at'] ?? now(),
            'informant_name' => $data['informant_name'] ?? $kunjungan->patient_name,
            'informant_relation' => $data['informant_relation'] ?? 'Pasien sendiri',
            'pharmacist_id' => $data['pharmacist_id'] ?? null,
            'pharmacist_name' => $data['pharmacist_name'] ?? $actor?->name,
            'allergies_at_interview' => [],
            'status' => MedicationReconciliation::DRAF,
            'recorded_by' => $actor?->id,
            'recorded_by_name' => $actor?->name,
        ]);
    }

    /**
     * Mencatat satu obat yang sedang dipakai pasien.
     *
     * NAMA OBAT BOLEH TEKS BEBAS. Yang direkonsiliasi adalah obat dari
     * luar — obat warung, obat faskes lain, obat yang tidak ada di
     * formularium kita. Mewajibkan drug_id membuat obat yang paling
     * perlu dicatat justru yang tidak bisa dicatat.
     *
     * @throws ClinicalException
     */
    public function addItem(
        MedicationReconciliation $reconciliation,
        string $drugName,
        array $data = [],
    ): MedicationReconciliationItem {
        $this->assertEditable($reconciliation);

        $nama = trim($drugName);

        if ($nama === '') {
            throw new ClinicalException('Nama obat wajib diisi.');
        }

        $keputusan = $data['decision'] ?? null;

        if ($keputusan !== null) {
            $this->assertDecision($keputusan, $data['new_instruction'] ?? null);
        }

        return MedicationReconciliationItem::query()->create([
            'reconciliation_id' => $reconciliation->id,
            'drug_name' => $nama,
            'drug_id' => $data['drug_id'] ?? null,
            'kfa_code' => $data['kfa_code'] ?? null,
            'dose' => $data['dose'] ?? null,
            'frequency' => $data['frequency'] ?? null,
            'route' => $data['route'] ?? null,
            'last_taken_at' => $data['last_taken_at'] ?? null,
            'source' => $data['source'] ?? null,
            'decision' => $keputusan,
            'new_instruction' => $data['new_instruction'] ?? null,
            'decision_reason' => $data['decision_reason'] ?? null,
        ]);
    }

    /**
     * @throws ClinicalException
     */
    public function decide(
        MedicationReconciliationItem $item,
        string $decision,
        array $data = [],
    ): MedicationReconciliationItem {
        $this->assertEditable($item->reconciliation);
        $this->assertDecision($decision, $data['new_instruction'] ?? null);

        $item->update([
            'decision' => $decision,
            'new_instruction' => $decision === MedicationReconciliationItem::UBAH_ATURAN
                ? $data['new_instruction']
                : null,
            'decision_reason' => $data['decision_reason'] ?? $item->decision_reason,
        ]);

        return $item->refresh();
    }

    /**
     * Mencatat alergi yang ditemukan saat wawancara.
     *
     * Masuk ke DAFTAR ALERGI PASIEN, bukan ke tabel rekonsiliasi. Itulah
     * intinya: alergi yang ditemukan di sini harus ikut terbaca saat
     * apoteker menelaah resep berikutnya.
     *
     * @throws ClinicalException
     */
    public function recordAllergy(
        MedicationReconciliation $reconciliation,
        string $substance,
        array $attributes = [],
        ?User $actor = null,
    ): Allergy {
        $this->assertEditable($reconciliation);

        return $this->records->recordAllergy(
            $reconciliation->patient_id,
            $substance,
            $attributes + ['category' => 'obat'],
            $reconciliation->registration_id,
            $actor,
        );
    }

    // ------------------------------------------------------ rantai konfirmasi

    /**
     * @throws ClinicalException
     */
    public function receiveByPharmacy(MedicationReconciliation $reconciliation, ?User $actor = null): MedicationReconciliation
    {
        if ($reconciliation->received_by_pharmacy_at !== null) {
            throw new ClinicalException('Rekonsiliasi ini sudah diterima farmasi.');
        }

        $reconciliation->update([
            'received_by_pharmacy_at' => now(),
            'pharmacist_name' => $reconciliation->pharmacist_name ?? $actor?->name,
        ]);

        return $reconciliation->refresh();
    }

    /**
     * @throws ClinicalException
     */
    public function confirmByPharmacist(MedicationReconciliation $reconciliation, ?User $actor = null): MedicationReconciliation
    {
        if ($reconciliation->received_by_pharmacy_at === null) {
            throw new ClinicalException(
                'Rekonsiliasi belum tercatat diterima farmasi, jadi belum bisa dikonfirmasi apoteker. '
                .'Urutannya diterima, dikonfirmasi, lalu diserahkan.'
            );
        }

        if ($reconciliation->confirmed_by_pharmacist_at !== null) {
            throw new ClinicalException('Rekonsiliasi ini sudah dikonfirmasi apoteker.');
        }

        $reconciliation->update([
            'confirmed_by_pharmacist_at' => now(),
            'pharmacist_id' => $reconciliation->pharmacist_id ?? $actor?->id,
            'pharmacist_name' => $actor?->name ?? $reconciliation->pharmacist_name,
        ]);

        return $reconciliation->refresh();
    }

    /**
     * @throws ClinicalException
     */
    public function handToPatient(MedicationReconciliation $reconciliation): MedicationReconciliation
    {
        if ($reconciliation->confirmed_by_pharmacist_at === null) {
            throw new ClinicalException(
                'Rekonsiliasi belum dikonfirmasi apoteker, jadi belum boleh diserahkan ke pasien. '
                .'Menyerahkan daftar obat yang belum ditelaah sama saja meneruskan obat tanpa pemeriksaan.'
            );
        }

        if ($reconciliation->handed_to_patient_at !== null) {
            throw new ClinicalException('Rekonsiliasi ini sudah diserahkan ke pasien.');
        }

        $reconciliation->update(['handed_to_patient_at' => now()]);

        return $reconciliation->refresh();
    }

    // ----------------------------------------------------------- finalisasi

    /**
     * @throws ClinicalException
     */
    public function finalize(MedicationReconciliation $reconciliation, ?User $actor = null): MedicationReconciliation
    {
        $this->assertEditable($reconciliation);

        if (blank($reconciliation->pharmacist_name)) {
            throw new ClinicalException(
                'Nama apoteker yang melakukan rekonsiliasi wajib disebut: keputusan meneruskan atau '
                .'menghentikan obat adalah pertimbangan seseorang.'
            );
        }

        $belum = $reconciliation->items()
            ->whereNull('decision')
            ->pluck('drug_name')
            ->all();

        if ($belum !== []) {
            throw new ClinicalException(
                'Obat berikut belum diputuskan diteruskan atau dihentikan: '.implode(', ', $belum)
                .'. Obat yang didaftar tanpa tindak lanjut membuat perawat di ruangan tidak tahu '
                .'apakah pasien boleh terus meminumnya.'
            );
        }

        $reconciliation->update([
            // Salinan dokumen; daftar yang hidup tetap clinical.allergies.
            'allergies_at_interview' => $this->freezeAllergies($reconciliation->patient_id),
            'status' => MedicationReconciliation::FINAL,
            'finalized_at' => now(),
            'finalized_by' => $actor?->id,
        ]);

        return $reconciliation->refresh();
    }

    /**
     * @throws ClinicalException
     */
    public function cancel(MedicationReconciliation $reconciliation, string $reason): MedicationReconciliation
    {
        $alasan = trim($reason);

        if ($alasan === '') {
            throw new ClinicalException('Alasan pembatalan wajib diisi.');
        }

        if ($reconciliation->status === MedicationReconciliation::DIBATALKAN) {
            throw new ClinicalException('Rekonsiliasi ini sudah dibatalkan.');
        }

        $reconciliation->update([
            'status' => MedicationReconciliation::DIBATALKAN,
            'note' => trim(($reconciliation->note ? $reconciliation->note.' ' : '')."[Dibatalkan: {$alasan}]"),
        ]);

        return $reconciliation->refresh();
    }

    // ---------------------------------------------------------------- baca

    public function forRegistration(int $registrationId): Collection
    {
        return MedicationReconciliation::query()
            ->where('registration_id', $registrationId)
            ->where('status', '<>', MedicationReconciliation::DIBATALKAN)
            ->with('items')
            ->orderBy('interviewed_at')
            ->get();
    }

    /**
     * Obat yang diputuskan diteruskan pada rekonsiliasi terakhir.
     *
     * Inilah yang perlu dibaca dokter saat menulis resep: apa saja yang
     * sudah dipakai pasien dan disepakati untuk dilanjutkan.
     */
    public function continuedMedications(int $registrationId): Collection
    {
        $terakhir = MedicationReconciliation::query()
            ->where('registration_id', $registrationId)
            ->where('status', MedicationReconciliation::FINAL)
            ->orderByDesc('interviewed_at')
            ->first();

        if ($terakhir === null) {
            return collect();
        }

        return $terakhir->items()
            ->whereIn('decision', [
                MedicationReconciliationItem::LANJUT,
                MedicationReconciliationItem::UBAH_ATURAN,
            ])
            ->orderBy('drug_name')
            ->get();
    }

    // ------------------------------------------------------------ internal

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

    /**
     * @throws ClinicalException
     */
    private function assertDecision(string $decision, ?string $newInstruction): void
    {
        if (! array_key_exists($decision, MedicationReconciliationItem::TINDAK_LANJUT)) {
            throw new ClinicalException(
                "Tindak lanjut '{$decision}' tidak dikenali. Pilihannya: "
                .implode(', ', array_keys(MedicationReconciliationItem::TINDAK_LANJUT)).'.'
            );
        }

        if ($decision === MedicationReconciliationItem::UBAH_ATURAN && blank($newInstruction)) {
            throw new ClinicalException(
                'Aturan pakai yang baru wajib diisi saat obat diteruskan dengan aturan berbeda. '
                .'Tanpa itu instruksinya tidak bisa dijalankan siapa pun.'
            );
        }
    }

    /**
     * @throws ClinicalException
     */
    private function assertEditable(MedicationReconciliation $reconciliation): void
    {
        if ($reconciliation->isEditable()) {
            return;
        }

        throw new ClinicalException(
            $reconciliation->status === MedicationReconciliation::FINAL
                ? 'Rekonsiliasi yang sudah difinalkan tidak bisa diubah. Buat rekonsiliasi baru pada '
                    .'kesempatan berikutnya bila daftar obatnya berubah.'
                : 'Rekonsiliasi ini sudah dibatalkan.'
        );
    }
}
