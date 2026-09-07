<?php

namespace App\Modules\Clinical\Services;

use App\Modules\Clinical\Models\MedicalRecordDocument;
use App\Modules\Clinical\Models\RecordRetention;
use App\Modules\Platform\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Berkas digital & retensi rekam medis (domain M item L).
 *
 * EMPAT ATURAN.
 *
 * 1. SETIAP BERKAS PUNYA PENGUNGGAH, WAKTU, DAN SIDIK. Khanza hanya
 *    menyimpan no_rawat, kode, dan jalur berkasnya — berkas yang muncul
 *    tanpa pengunggah tidak bisa dipertanggungjawabkan, dan berkas di
 *    jalur yang sama bisa ditimpa tanpa meninggalkan bekas. Sidik
 *    SHA-256 di sini bukan pengunci; gunanya membuat penimpaan
 *    KETAHUAN lewat verify().
 *
 * 2. RALAT TIDAK MENIMPA. Berkas yang salah ditandai diganti dan
 *    menunjuk penggantinya; yang lama tetap ada. Rekam medis yang bisa
 *    dihapus tanpa jejak bukan rekam medis.
 *
 * 3. TANGGAL RETENSI DIHITUNG DARI KUNJUNGAN TERAKHIR, tidak disimpan
 *    — dan kunjungan terakhirnya sendiri dibaca ulang dari encounter,
 *    bukan ditunggu diperbarui manual. Pasien yang berobat lagi
 *    otomatis memundurkan masa simpannya; pada Khanza, terakhir_daftar
 *    yang tidak diperbarui akan membuat rekam medis pasien aktif
 *    diusulkan musnah.
 *
 * 4. PEMUSNAHAN MENUNTUT BERITA ACARA DAN TIDAK BOLEH MENDAHULUI MASA
 *    SIMPANNYA. Ditegakkan service dan basis data. Berkas berjenis
 *    permanen tidak ikut musnah.
 */
class MedicalRecordFileService
{
    private const REGISTRASI = 'encounter.v_registration_summary';

    private const JENIS_BERKAS = 'catalog.v_document_type';

    /**
     * Mencatat berkas yang diunggah.
     *
     * Yang diterima di sini metadata berkasnya; penyimpanan berkas
     * sendiri urusan lapisan di atasnya. Yang dijaga tabel ini adalah
     * bahwa metadatanya lengkap — pengunggah, waktu, tipe, besar, sidik.
     *
     * @throws ClinicalException
     */
    public function attach(
        int $registrationId,
        string $documentTypeCode,
        array $file,
        ?User $actor = null,
    ): MedicalRecordDocument {
        $kunjungan = DB::table(self::REGISTRASI)->where('id', $registrationId)->first()
            ?? throw new ClinicalException('Kunjungan tidak ditemukan atau sudah dibatalkan.');

        $jenis = DB::table(self::JENIS_BERKAS)
            ->where('code', $documentTypeCode)
            ->where('is_active', true)
            ->first()
            ?? throw new ClinicalException(
                "Jenis berkas '{$documentTypeCode}' tidak ada atau sudah tidak aktif."
            );

        $pengunggah = trim($file['uploaded_by_name'] ?? $actor?->name ?? '');

        if ($pengunggah === '') {
            throw new ClinicalException(
                'Nama pengunggah wajib dicatat. Berkas rekam medis yang muncul tanpa pengunggah tidak '
                .'bisa dipertanggungjawabkan, dan Permenkes 24/2022 menuntut jejaknya.'
            );
        }

        $sidik = strtolower(trim($file['checksum_sha256'] ?? ''));

        if (! preg_match('/^[0-9a-f]{64}$/', $sidik)) {
            throw new ClinicalException(
                'Sidik SHA-256 berkas wajib dan harus 64 karakter heksadesimal. Tanpa sidik, penggantian '
                .'berkas di jalur yang sama tidak akan pernah ketahuan.'
            );
        }

        $besar = (int) ($file['size_bytes'] ?? 0);

        if ($besar <= 0) {
            throw new ClinicalException('Ukuran berkas wajib diisi dan harus lebih dari nol.');
        }

        $sudahAda = MedicalRecordDocument::query()
            ->where('registration_id', $registrationId)
            ->where('document_type_code', $documentTypeCode)
            ->where('checksum_sha256', $sidik)
            ->where('status', MedicalRecordDocument::AKTIF)
            ->exists();

        if ($sudahAda) {
            throw new ClinicalException(
                'Berkas yang sama persis sudah terlampir sebagai jenis ini pada kunjungan ini. '
                .'Kemungkinan tombol unggah tertekan dua kali.'
            );
        }

        return MedicalRecordDocument::query()->create([
            'registration_id' => $registrationId,
            'patient_id' => $kunjungan->patient_id,
            'registration_number' => $kunjungan->registration_number,
            'patient_mrn' => $kunjungan->patient_mrn,
            'patient_name' => $kunjungan->patient_name,
            // Dibekukan: master boleh berubah nama.
            'document_type_code' => $jenis->code,
            'document_type_name' => $jenis->name,
            'original_filename' => $file['original_filename'],
            'stored_path' => $file['stored_path'],
            'mime_type' => $file['mime_type'],
            'size_bytes' => $besar,
            'checksum_sha256' => $sidik,
            'uploaded_by' => $actor?->id,
            'uploaded_by_name' => $pengunggah,
            'uploaded_at' => $file['uploaded_at'] ?? now(),
            'note' => $file['note'] ?? null,
            'status' => MedicalRecordDocument::AKTIF,
        ]);
    }

    /**
     * Mengganti berkas yang salah dengan yang benar.
     *
     * Yang lama TIDAK dihapus: ditandai diganti dan menunjuk
     * penggantinya, sehingga pertanyaan "kenapa berkasnya berubah"
     * masih bisa dijawab.
     *
     * @throws ClinicalException
     */
    public function replace(
        MedicalRecordDocument $document,
        array $file,
        ?User $actor = null,
    ): MedicalRecordDocument {
        if (! $document->isActive()) {
            throw new ClinicalException(
                $document->status === MedicalRecordDocument::DIGANTI
                    ? 'Berkas ini sudah pernah diganti. Ganti berkas penggantinya, bukan yang lama.'
                    : 'Berkas yang dibatalkan tidak bisa diganti.'
            );
        }

        $pengganti = $this->attach(
            $document->registration_id,
            $document->document_type_code,
            $file,
            $actor,
        );

        $document->update([
            'status' => MedicalRecordDocument::DIGANTI,
            'superseded_by_id' => $pengganti->id,
        ]);

        return $pengganti;
    }

    /**
     * @throws ClinicalException
     */
    public function cancel(MedicalRecordDocument $document, string $reason): MedicalRecordDocument
    {
        $alasan = trim($reason);

        if ($alasan === '') {
            throw new ClinicalException('Alasan pembatalan wajib diisi.');
        }

        if ($document->status === MedicalRecordDocument::DIBATALKAN) {
            throw new ClinicalException('Berkas ini sudah dibatalkan.');
        }

        $document->update([
            'status' => MedicalRecordDocument::DIBATALKAN,
            'cancellation_reason' => $alasan,
        ]);

        return $document->refresh();
    }

    /**
     * Memeriksa apakah isi berkas masih sama dengan saat diunggah.
     *
     * @throws ClinicalException
     */
    public function verify(MedicalRecordDocument $document, string $currentChecksum): bool
    {
        $sidik = strtolower(trim($currentChecksum));

        if (! preg_match('/^[0-9a-f]{64}$/', $sidik)) {
            throw new ClinicalException('Sidik pembanding harus SHA-256 heksadesimal 64 karakter.');
        }

        return $document->matches($sidik);
    }

    /** Berkas yang berlaku pada satu kunjungan. */
    public function forRegistration(int $registrationId, ?string $typeCode = null): Collection
    {
        return MedicalRecordDocument::query()
            ->where('registration_id', $registrationId)
            ->where('status', MedicalRecordDocument::AKTIF)
            ->when($typeCode, fn ($q) => $q->where('document_type_code', $typeCode))
            ->orderBy('uploaded_at')
            ->get();
    }

    // ------------------------------------------------------------- retensi

    /**
     * Menyegarkan catatan retensi seorang pasien dari kunjungan
     * terakhirnya yang sebenarnya.
     *
     * Dibaca ulang, bukan ditunggu diperbarui manual: pasien yang
     * berobat lagi harus otomatis memundurkan masa simpannya, dan
     * terakhir_daftar yang basi akan membuat rekam medis pasien aktif
     * diusulkan musnah.
     *
     * @throws ClinicalException
     */
    public function refreshRetention(int $patientId): RecordRetention
    {
        $terakhir = DB::table(self::REGISTRASI)
            ->where('patient_id', $patientId)
            ->orderByDesc('service_date')
            ->first()
            ?? throw new ClinicalException(
                'Pasien ini belum punya kunjungan, jadi masa simpannya belum mulai berjalan.'
            );

        $catatan = RecordRetention::query()->firstOrNew(['patient_id' => $patientId]);

        if ($catatan->isDestroyed()) {
            throw new ClinicalException(
                'Rekam medis pasien ini sudah dimusnahkan; catatannya tidak bisa disegarkan lagi.'
            );
        }

        $catatan->fill([
            'patient_mrn' => $terakhir->patient_mrn,
            'patient_name' => $terakhir->patient_name,
            'last_visit_on' => $terakhir->service_date,
        ]);

        $catatan->status ??= RecordRetention::AKTIF;
        $catatan->save();

        return $catatan->refresh();
    }

    /**
     * Menandai rekam medis sebagai diabadikan — tidak ikut dimusnahkan
     * meski masa simpannya lewat.
     *
     * @throws ClinicalException
     */
    public function markPermanent(RecordRetention $retention, string $reason): RecordRetention
    {
        $alasan = trim($reason);

        if ($alasan === '') {
            throw new ClinicalException('Alasan pengabadian wajib diisi.');
        }

        if ($retention->isDestroyed()) {
            throw new ClinicalException('Rekam medis yang sudah dimusnahkan tidak bisa diabadikan.');
        }

        $retention->update([
            'status' => RecordRetention::DIABADIKAN,
            'note' => trim(($retention->note ? $retention->note.' ' : '')."[Diabadikan: {$alasan}]"),
        ]);

        return $retention->refresh();
    }

    /**
     * Mengusulkan pemusnahan.
     *
     * @throws ClinicalException
     */
    public function propose(RecordRetention $retention, string $proposalNumber): RecordRetention
    {
        $nomor = trim($proposalNumber);

        if ($nomor === '') {
            throw new ClinicalException('Nomor usulan pemusnahan wajib diisi.');
        }

        $this->assertDestroyable($retention);

        $retention->update([
            'status' => RecordRetention::DIUSULKAN_MUSNAH,
            'proposed_on' => now()->toDateString(),
            'proposal_number' => $nomor,
        ]);

        return $retention->refresh();
    }

    /**
     * Mencatat pemusnahan.
     *
     * @throws ClinicalException
     */
    public function destroy(
        RecordRetention $retention,
        string $decisionNumber,
        ?string $summaryPath = null,
        ?User $actor = null,
    ): RecordRetention {
        $nomor = trim($decisionNumber);

        if ($nomor === '') {
            throw new ClinicalException(
                'Nomor berita acara pemusnahan wajib diisi. Pemusnahan rekam medis tidak bisa dibatalkan, '
                .'dan syaratnya keputusan tertulis — bukan kehendak seorang petugas.'
            );
        }

        if ($retention->status !== RecordRetention::DIUSULKAN_MUSNAH) {
            throw new ClinicalException(
                'Pemusnahan hanya boleh atas rekam medis yang sudah diusulkan musnah. Usulkan lebih dulu '
                .'supaya ada kesempatan meninjau sebelum berkasnya hilang.'
            );
        }

        $this->assertDestroyable($retention);

        $penanggungJawab = trim($actor?->name ?? '');

        if ($penanggungJawab === '') {
            throw new ClinicalException('Nama petugas yang memusnahkan wajib tercatat.');
        }

        $retention->update([
            'status' => RecordRetention::DIMUSNAHKAN,
            'destroyed_at' => now(),
            'destruction_decision_number' => $nomor,
            'destroyed_by' => $actor?->id,
            'destroyed_by_name' => $penanggungJawab,
            'summary_path' => $summaryPath ?? $retention->summary_path,
        ]);

        return $retention->refresh();
    }

    /**
     * Rekam medis yang masa simpannya sudah lewat dan belum ditindak.
     *
     * Batasnya DIHITUNG dari kunjungan terakhir plus masa simpan, jadi
     * saat peraturannya berubah cukup satu konstanta yang diubah dan
     * daftar ini ikut benar.
     */
    public function due(?Carbon $on = null): Collection
    {
        $batas = ($on ?? now())->copy()->subYears(RecordRetention::MASA_SIMPAN_TAHUN)->toDateString();

        return RecordRetention::query()
            ->where('status', RecordRetention::AKTIF)
            ->whereDate('last_visit_on', '<=', $batas)
            ->orderBy('last_visit_on')
            ->get();
    }

    /**
     * @throws ClinicalException
     */
    private function assertDestroyable(RecordRetention $retention): void
    {
        if ($retention->status === RecordRetention::DIABADIKAN) {
            throw new ClinicalException(
                'Rekam medis ini diabadikan dan tidak ikut dimusnahkan.'
            );
        }

        if ($retention->isDestroyed()) {
            throw new ClinicalException('Rekam medis ini sudah dimusnahkan.');
        }

        if (! $retention->isDue()) {
            throw new ClinicalException(sprintf(
                'Masa simpan rekam medis ini baru habis %s (%d tahun sejak kunjungan terakhir, '
                .'Permenkes 24/2022). Belum boleh dimusnahkan.',
                $retention->dueOn()->toDateString(),
                RecordRetention::MASA_SIMPAN_TAHUN,
            ));
        }
    }
}
