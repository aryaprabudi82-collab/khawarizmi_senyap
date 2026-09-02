<?php

namespace App\Modules\Clinical\Services;

use Illuminate\Support\Facades\DB;
use stdClass;

/**
 * Satu-satunya tempat konteks clinical menyentuh data milik konteks encounter.
 *
 * Dibaca lewat encounter.v_registration_summary — view yang sengaja diterbitkan
 * konteks encounter dan terdaftar di config/contexts.php. Tabel di baliknya
 * boleh dirombak kapan saja asal bentuk view-nya tetap.
 *
 * Kopling lintas konteks sengaja dikumpulkan di satu berkas: kalau kontraknya
 * berubah, yang perlu disesuaikan hanya kelas ini.
 */
class RegistrationContext
{
    private const VIEW = 'encounter.v_registration_summary';

    public function find(int $registrationId): ?stdClass
    {
        return DB::table(self::VIEW)->where('id', $registrationId)->first();
    }

    /**
     * Kunjungan yang menunggu atau sedang dilayani pada satu tanggal.
     *
     * Dipakai layar "pasien menunggu pemeriksaan". Disaring per unit dan per
     * dokter supaya seorang dokter hanya melihat antreannya sendiri.
     */
    public function waitingOn(
        string $date,
        ?int $unitId = null,
        ?int $practitionerId = null,
        int $limit = 100,
    ): \Illuminate\Support\Collection {
        return DB::table(self::VIEW)
            ->whereDate('service_date', $date)
            ->whereIn('status', ['terdaftar', 'dipanggil', 'dilayani'])
            ->when($unitId, fn ($q) => $q->where('unit_id', $unitId))
            ->when($practitionerId, fn ($q) => $q->where('practitioner_id', $practitionerId))
            ->orderBy('unit_name')
            ->orderBy('queue_number')
            ->limit($limit)
            ->get();
    }

    /** Riwayat kunjungan seorang pasien, terbaru lebih dulu. */
    public function historyFor(int $patientId, int $limit = 20): \Illuminate\Support\Collection
    {
        return DB::table(self::VIEW)
            ->where('patient_id', $patientId)
            ->orderByDesc('service_date')
            ->orderByDesc('registered_at')
            ->limit($limit)
            ->get();
    }
}
