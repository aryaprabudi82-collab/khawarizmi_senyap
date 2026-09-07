<?php

namespace App\Modules\Clinical\Services;

use App\Modules\Clinical\Models\Immunisation;
use App\Modules\Clinical\Models\PatientDisability;
use App\Modules\Platform\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Riwayat imunisasi & cacat fisik pasien (domain M item S).
 *
 * LIMA ATURAN.
 *
 * 1. TANGGAL PEMBERIAN WAJIB. riwayat_imunisasi Khanza tidak punya
 *    kolomnya sama sekali, dan tanpa tanggal jadwal dosis berikutnya
 *    tidak bisa dihitung, cakupan per periode tidak bisa dilaporkan,
 *    dan penarikan batch tidak bisa dibatasi rentang waktunya.
 *
 * 2. NOMOR BATCH DIWAJIBKAN UNTUK YANG DISUNTIKKAN DI SINI. Saat satu
 *    batch ditarik peredarannya, satu-satunya cara menemukan pasien
 *    yang menerimanya adalah lewat nomor itu. Untuk dosis dari
 *    fasilitas lain tidak diwajibkan — kita memang tidak memilikinya,
 *    dan mewajibkannya hanya akan melahirkan nomor karangan.
 *
 * 3. DOSIS DARI LUAR DIBEDAKAN. Yang dilaporkan keluarga tanpa bukti
 *    tidak setara dengan yang kita suntikkan sendiri, dan cakupan yang
 *    mencampur keduanya melebih-lebihkan capaian.
 *
 * 4. VAKSIN KEDALUWARSA TIDAK DISIMPAN DIAM-DIAM: ditolak saat
 *    pencatatan dan ditegakkan CHECK.
 *
 * 5. CACAT FISIK MELEKAT PADA PASIEN. Khanza hanya punya masternya;
 *    pasien mana punya cacat yang mana tidak pernah dibuatkan tempat.
 */
class ImmunisationService
{
    private const REGISTRASI = 'encounter.v_registration_summary';

    private const JENIS_IMUNISASI = 'catalog.v_immunisation_type';

    private const JENIS_CACAT = 'catalog.v_disability_type';

    /**
     * Mencatat satu dosis imunisasi.
     *
     * @throws ClinicalException
     */
    public function record(int $patientId, string $code, array $data, ?User $actor = null): Immunisation
    {
        $jenis = DB::table(self::JENIS_IMUNISASI)
            ->where('code', $code)
            ->where('is_active', true)
            ->first()
            ?? throw new ClinicalException("Jenis imunisasi '{$code}' tidak ada atau sudah tidak aktif.");

        $tanggal = $data['given_on'] ?? null;

        if (blank($tanggal)) {
            throw new ClinicalException(
                'Tanggal pemberian wajib diisi. Tanpa tanggal, jadwal dosis berikutnya tidak bisa '
                .'dihitung dan cakupan imunisasi tidak bisa dilaporkan per periode.'
            );
        }

        $tanggal = Carbon::parse($tanggal);

        if ($tanggal->isFuture()) {
            throw new ClinicalException('Tanggal pemberian tidak boleh di masa depan.');
        }

        $dosis = $data['dose_number'] ?? null;

        if (! is_int($dosis) || $dosis < 1) {
            throw new ClinicalException('Nomor dosis wajib diisi dan harus bilangan bulat mulai dari 1.');
        }

        if ($jenis->total_doses !== null && $dosis > $jenis->total_doses) {
            throw new ClinicalException(sprintf(
                'Dosis ke-%d melebihi jumlah dosis lengkap %s yang hanya %d. Bila memang ada dosis '
                .'penguat tambahan, jumlah dosisnya perlu diperbarui di master lebih dulu.',
                $dosis, $jenis->name, $jenis->total_doses,
            ));
        }

        $sumber = $data['source'] ?? 'rumah-sakit-ini';

        if (! array_key_exists($sumber, Immunisation::SUMBER)) {
            throw new ClinicalException("Sumber catatan '{$sumber}' tidak dikenali.");
        }

        $batch = trim($data['batch_number'] ?? '');

        if ($sumber === 'rumah-sakit-ini' && $batch === '') {
            throw new ClinicalException(
                'Nomor batch wajib diisi untuk vaksin yang disuntikkan di sini. Saat satu batch ditarik '
                .'peredarannya, nomor itulah satu-satunya cara menemukan pasien yang menerimanya.'
            );
        }

        $kedaluwarsa = filled($data['expires_on'] ?? null) ? Carbon::parse($data['expires_on']) : null;

        if ($kedaluwarsa !== null && $kedaluwarsa->lessThan($tanggal)) {
            throw new ClinicalException(
                'Vaksin sudah kedaluwarsa pada tanggal pemberiannya. Ini kejadian yang harus '
                .'ditindaklanjuti, bukan dicatat begitu saja.'
            );
        }

        $sudahAda = Immunisation::query()
            ->where('patient_id', $patientId)
            ->where('immunisation_code', $code)
            ->where('dose_number', $dosis)
            ->where('status', Immunisation::TERCATAT)
            ->exists();

        if ($sudahAda) {
            throw new ClinicalException(
                "Dosis ke-{$dosis} {$jenis->name} sudah tercatat untuk pasien ini."
            );
        }

        $identitas = $this->patientIdentity($patientId, $data['registration_id'] ?? null);

        return Immunisation::query()->create([
            'patient_id' => $patientId,
            'patient_mrn' => $identitas['mrn'],
            'patient_name' => $identitas['name'],
            'registration_id' => $data['registration_id'] ?? null,
            'immunisation_code' => $jenis->code,
            // Disalin: nama master boleh berubah, yang tercatat tidak.
            'immunisation_name' => $jenis->name,
            'dose_number' => $dosis,
            'given_on' => $tanggal->toDateString(),
            'batch_number' => $batch !== '' ? $batch : null,
            'expires_on' => $kedaluwarsa?->toDateString(),
            'injection_site' => $data['injection_site'] ?? null,
            'route' => $data['route'] ?? $jenis->route,
            'source' => $sumber,
            'facility_name' => $data['facility_name'] ?? null,
            'given_by' => $actor?->id,
            'given_by_name' => $data['given_by_name'] ?? $actor?->name,
            'reaction' => $data['reaction'] ?? null,
            'status' => Immunisation::TERCATAT,
            'recorded_by' => $actor?->id,
            'recorded_by_name' => $actor?->name,
        ]);
    }

    /**
     * @throws ClinicalException
     */
    public function cancel(Immunisation $immunisation, string $reason): Immunisation
    {
        $alasan = trim($reason);

        if ($alasan === '') {
            throw new ClinicalException('Alasan pembatalan wajib diisi.');
        }

        if (! $immunisation->isActive()) {
            throw new ClinicalException('Catatan imunisasi ini sudah dibatalkan.');
        }

        $immunisation->update([
            'status' => Immunisation::DIBATALKAN,
            'cancellation_reason' => $alasan,
        ]);

        return $immunisation->refresh();
    }

    /** Riwayat imunisasi seorang pasien. */
    public function historyFor(int $patientId): Collection
    {
        return Immunisation::query()
            ->where('patient_id', $patientId)
            ->where('status', Immunisation::TERCATAT)
            ->orderBy('given_on')
            ->get();
    }

    /**
     * Kapan dosis berikutnya jatuh tempo untuk satu jenis imunisasi.
     *
     * Mengembalikan null bila serinya sudah lengkap, dan itu berbeda
     * dari "belum ada jadwalnya".
     *
     * @return array{status: string, due_on: ?string, next_dose: ?int}
     *
     * @throws ClinicalException
     */
    public function nextDoseFor(int $patientId, string $code): array
    {
        $jenis = DB::table(self::JENIS_IMUNISASI)->where('code', $code)->first()
            ?? throw new ClinicalException("Jenis imunisasi '{$code}' tidak dikenali.");

        $terakhir = Immunisation::query()
            ->where('patient_id', $patientId)
            ->where('immunisation_code', $code)
            ->where('status', Immunisation::TERCATAT)
            ->orderByDesc('dose_number')
            ->first();

        if ($terakhir === null) {
            return ['status' => 'belum-mulai', 'due_on' => null, 'next_dose' => 1];
        }

        if ($jenis->total_doses !== null && $terakhir->dose_number >= $jenis->total_doses) {
            return ['status' => 'lengkap', 'due_on' => null, 'next_dose' => null];
        }

        $jatuhTempo = $terakhir->nextDoseDueOn($jenis->interval_days);

        return [
            'status' => $jatuhTempo === null ? 'jarak-belum-diatur' : 'terjadwal',
            'due_on' => $jatuhTempo?->toDateString(),
            'next_dose' => $terakhir->dose_number + 1,
        ];
    }

    /**
     * Pasien yang menerima vaksin dari satu batch.
     *
     * Inilah gunanya nomor batch diwajibkan: saat batch ditarik, daftar
     * ini yang dihubungi. Pada riwayat_imunisasi Khanza pertanyaannya
     * tidak bisa diajukan sama sekali.
     */
    public function recipientsOfBatch(string $batchNumber): Collection
    {
        return Immunisation::query()
            ->where('batch_number', $batchNumber)
            ->where('status', Immunisation::TERCATAT)
            ->orderBy('given_on')
            ->get();
    }

    // ------------------------------------------------------- cacat fisik

    /**
     * Mencatat cacat fisik seorang pasien.
     *
     * @throws ClinicalException
     */
    public function recordDisability(
        int $patientId,
        string $code,
        array $data = [],
        ?User $actor = null,
    ): PatientDisability {
        $jenis = DB::table(self::JENIS_CACAT)
            ->where('code', $code)
            ->where('is_active', true)
            ->first()
            ?? throw new ClinicalException("Jenis cacat fisik '{$code}' tidak ada atau sudah tidak aktif.");

        $onset = $data['onset'] ?? null;

        if ($onset !== null && ! in_array($onset, [PatientDisability::BAWAAN, PatientDisability::DIDAPAT], true)) {
            throw new ClinicalException("Asal cacat '{$onset}' tidak dikenali. Pilihannya: bawaan, didapat.");
        }

        if ($onset === PatientDisability::BAWAAN && filled($data['onset_on'] ?? null)) {
            throw new ClinicalException(
                'Cacat bawaan tidak menyebut tanggal mulai: ia ada sejak lahir. Bila tanggalnya diketahui '
                .'karena baru muncul kemudian, asalnya "didapat".'
            );
        }

        $sudahAda = PatientDisability::query()
            ->where('patient_id', $patientId)
            ->where('disability_code', $code)
            ->where('status', PatientDisability::AKTIF)
            ->exists();

        if ($sudahAda) {
            throw new ClinicalException("Cacat '{$jenis->name}' sudah tercatat aktif untuk pasien ini.");
        }

        $identitas = $this->patientIdentity($patientId, $data['recorded_in_registration_id'] ?? null);

        return PatientDisability::query()->create([
            'patient_id' => $patientId,
            'patient_mrn' => $identitas['mrn'],
            'patient_name' => $identitas['name'],
            'recorded_in_registration_id' => $data['recorded_in_registration_id'] ?? null,
            'disability_code' => $jenis->code,
            'disability_name' => $jenis->name,
            'onset' => $onset,
            'onset_on' => $data['onset_on'] ?? null,
            'severity' => $data['severity'] ?? null,
            'assistive_device' => $data['assistive_device'] ?? null,
            'service_adjustment' => $data['service_adjustment'] ?? null,
            'status' => PatientDisability::AKTIF,
            'recorded_by' => $actor?->id,
            'recorded_by_name' => $actor?->name,
            'recorded_at' => now(),
        ]);
    }

    /**
     * @throws ClinicalException
     */
    public function closeDisability(PatientDisability $disability, string $status, string $reason): PatientDisability
    {
        if (! in_array($status, [PatientDisability::PULIH, PatientDisability::DIKOREKSI], true)) {
            throw new ClinicalException("Status '{$status}' tidak dikenali. Pilihannya: pulih, dikoreksi.");
        }

        $alasan = trim($reason);

        if ($alasan === '') {
            throw new ClinicalException(
                'Alasan wajib diisi. "Pulih" pada catatan cacat fisik adalah pernyataan besar, dan '
                .'"dikoreksi" berarti catatannya memang keliru — keduanya perlu dijelaskan.'
            );
        }

        if (! $disability->isActive()) {
            throw new ClinicalException('Catatan cacat fisik ini sudah tidak aktif.');
        }

        $disability->update(['status' => $status, 'status_reason' => $alasan]);

        return $disability->refresh();
    }

    /** Cacat fisik aktif seorang pasien. */
    public function disabilitiesFor(int $patientId): Collection
    {
        return PatientDisability::query()
            ->where('patient_id', $patientId)
            ->where('status', PatientDisability::AKTIF)
            ->orderBy('disability_name')
            ->get();
    }

    /**
     * Penyesuaian pelayanan yang perlu disiapkan untuk seorang pasien.
     *
     * @return array<int, string>
     */
    public function serviceAdjustmentsFor(int $patientId): array
    {
        return $this->disabilitiesFor($patientId)
            ->filter(fn (PatientDisability $d) => $d->needsServiceAdjustment())
            ->map(fn (PatientDisability $d) => trim(sprintf(
                '%s: %s',
                $d->disability_name,
                $d->service_adjustment ?: 'memakai '.$d->assistive_device,
            )))
            ->values()
            ->all();
    }

    // ------------------------------------------------------------ internal

    /**
     * @return array{mrn: string, name: string}
     *
     * @throws ClinicalException
     */
    private function patientIdentity(int $patientId, ?int $registrationId): array
    {
        $kunjungan = DB::table(self::REGISTRASI)
            ->when($registrationId, fn ($q) => $q->where('id', $registrationId))
            ->where('patient_id', $patientId)
            ->orderByDesc('service_date')
            ->first();

        if ($kunjungan !== null) {
            return ['mrn' => $kunjungan->patient_mrn, 'name' => $kunjungan->patient_name];
        }

        throw new ClinicalException(
            'Pasien ini belum punya kunjungan, jadi identitasnya belum bisa disalin ke catatan klinis.'
        );
    }
}
