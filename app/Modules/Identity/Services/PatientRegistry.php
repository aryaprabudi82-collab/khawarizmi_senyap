<?php

namespace App\Modules\Identity\Services;

use App\Modules\Identity\Models\Patient;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Pintu masuk konteks identity.
 *
 * Konteks lain memanggil kelas ini, bukan menyentuh identity.patients langsung.
 */
class PatientRegistry
{
    /**
     * Mengalokasikan nomor rekam medis berikutnya.
     *
     * Satu pernyataan UPDATE ... RETURNING, sehingga PostgreSQL yang menjamin
     * tidak ada dua loket mendapat nomor yang sama. Khanza memakai
     * SELECT MAX(no_rkm_medis)+1 — pada 2.000 pendaftaran per hari dengan
     * beberapa loket paralel, itu menghasilkan nomor kembar.
     *
     * 8 digit sesuai ketentuan RSP UI — menampung sampai 99.999.999 nomor
     * per prefix sebelum perlu prefix baru, jauh di atas proyeksi
     * 2.000 pendaftaran/hari (~730.000/tahun).
     */
    public function allocateMedicalRecordNumber(string $prefix = ''): string
    {
        $key = $prefix === '' ? 'default' : $prefix;

        $row = DB::selectOne(
            'INSERT INTO identity.number_sequences (prefix, last_number, updated_at)
             VALUES (?, 1, now())
             ON CONFLICT (prefix) DO UPDATE
                SET last_number = identity.number_sequences.last_number + 1,
                    updated_at  = now()
             RETURNING last_number',
            [$key]
        );

        return $prefix . str_pad((string) $row->last_number, 8, '0', STR_PAD_LEFT);
    }

    /**
     * Kandidat duplikat sebelum pasien baru dibuat.
     *
     * NIK yang sama dianggap pasti duplikat. Tanpa NIK, kombinasi nama dan
     * tanggal lahir hanya dianggap dugaan — petugas yang memutuskan, bukan
     * sistem, karena nama kembar dengan tanggal lahir sama benar-benar terjadi.
     */
    public function findDuplicateCandidates(array $data): Collection
    {
        $nik = $data['nik'] ?? null;

        if ($nik) {
            $exact = Patient::query()->where('nik', $nik)->get();

            if ($exact->isNotEmpty()) {
                return $exact;
            }
        }

        if (empty($data['name']) || empty($data['birth_date'])) {
            return collect();
        }

        return Patient::query()
            ->whereRaw('lower(name) = lower(?)', [$data['name']])
            ->whereDate('birth_date', $data['birth_date'])
            ->get();
    }

    /**
     * Mendaftarkan pasien baru.
     *
     * @throws DuplicatePatientException bila NIK sudah terpakai
     */
    public function register(array $data, ?int $actorId = null): Patient
    {
        return DB::transaction(function () use ($data, $actorId): Patient {
            if (! empty($data['nik'])) {
                $existing = Patient::query()->where('nik', $data['nik'])->first();

                if ($existing !== null) {
                    throw new DuplicatePatientException($existing, 'NIK sudah terdaftar atas nama ' . $existing->name . '.');
                }
            }

            $data['medical_record_number'] ??= $this->allocateMedicalRecordNumber();
            $data['registered_on'] ??= now()->toDateString();
            $data['created_by'] = $actorId;
            $data['updated_by'] = $actorId;

            return Patient::query()->create($data);
        });
    }

    public function find(int $id): ?Patient
    {
        return Patient::query()->find($id);
    }

    public function findByMedicalRecordNumber(string $mrn): ?Patient
    {
        return Patient::query()->where('medical_record_number', $mrn)->first();
    }

    /** Pencarian untuk loket pendaftaran. */
    public function search(string $term, int $limit = 25): Collection
    {
        return Patient::query()
            ->search($term)
            ->orderBy('name')
            ->limit($limit)
            ->get();
    }
}
