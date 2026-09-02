<?php

namespace App\Modules\Encounter\Services;

use DateTimeInterface;
use Illuminate\Support\Facades\DB;

/**
 * Pengalokasi nomor antrean harian per unit.
 *
 * Seluruh penambahan terjadi di dalam satu pernyataan SQL, sehingga dua loket
 * yang menekan simpan pada saat yang sama tetap mendapat nomor berbeda.
 *
 * Bandingkan dengan pola Khanza:
 *
 *     SELECT MAX(no_reg) + 1 FROM reg_periksa WHERE ...   -- lalu INSERT
 *
 * Di antara SELECT dan INSERT ada celah. Pada 2.000 pendaftaran per hari yang
 * menumpuk di jam 07.00-11.00, celah itu pasti tertembak — dan gejalanya baru
 * terlihat sebagai dua pasien memegang nomor antrean yang sama.
 */
class QueueNumberAllocator
{
    public function allocate(DateTimeInterface $date, int $unitId, ?int $dailyQuota = null): int
    {
        $row = DB::selectOne(
            'INSERT INTO encounter.queue_counters (service_date, unit_id, last_number, updated_at)
             VALUES (?, ?, 1, now())
             ON CONFLICT (service_date, unit_id) DO UPDATE
                SET last_number = encounter.queue_counters.last_number + 1,
                    updated_at  = now()
             RETURNING last_number',
            [$date->format('Y-m-d'), $unitId]
        );

        $number = (int) $row->last_number;

        if ($dailyQuota !== null && $number > $dailyQuota) {
            throw new RegistrationException(
                "Kuota harian unit ini sudah penuh ({$dailyQuota} pasien)."
            );
        }

        return $number;
    }

    /** Nomor terakhir yang sudah dikeluarkan, tanpa mengambil nomor baru. */
    public function current(DateTimeInterface $date, int $unitId): int
    {
        $row = DB::selectOne(
            'SELECT last_number FROM encounter.queue_counters WHERE service_date = ? AND unit_id = ?',
            [$date->format('Y-m-d'), $unitId]
        );

        return (int) ($row->last_number ?? 0);
    }
}
