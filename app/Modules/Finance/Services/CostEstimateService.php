<?php

namespace App\Modules\Finance\Services;

use App\Modules\Finance\Models\InpatientCostEstimate;
use App\Modules\Platform\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * perkiraan_biaya_ranap — kutipan biaya ranap sebelum admisi.
 *
 * Bukan uang sungguhan, tidak dijurnal. Tarif kamar diambil dari
 * RoomRateContext (rata-rata per kelas, lihat catatan migrasi
 * inpatient.v_room_class_rate) dan disalin ke baris saat estimasi dibuat —
 * perubahan tarif kamar sesudahnya tidak mengubah makna estimasi yang
 * sudah dikomunikasikan ke pasien, pola sama dengan orders.order_items.
 */
class CostEstimateService
{
    public function __construct(
        private readonly RegistrationContext $registrations,
        private readonly RoomRateContext $roomRates,
    ) {}

    /**
     * @throws FinanceException
     */
    public function create(
        int $registrationId,
        string $roomClass,
        int $estimatedDays,
        float $otherCharges = 0,
        ?string $note = null,
        ?User $actor = null,
    ): InpatientCostEstimate {
        if (! in_array($roomClass, InpatientCostEstimate::CLASSES, true)) {
            throw new FinanceException('Kelas kamar tidak dikenal.');
        }

        if ($estimatedDays <= 0) {
            throw new FinanceException('Perkiraan lama rawat harus lebih dari nol hari.');
        }

        if ($otherCharges < 0) {
            throw new FinanceException('Perkiraan biaya lain-lain tidak boleh negatif.');
        }

        $kunjungan = $this->registrations->find($registrationId)
            ?? throw new FinanceException('Kunjungan tidak ditemukan atau sudah dibatalkan.');

        if ($kunjungan->care_type !== 'ranap') {
            throw new FinanceException('Perkiraan biaya ranap hanya untuk kunjungan dengan jenis rawat Rawat Inap.');
        }

        $dailyRate = $this->roomRates->rateFor($roomClass)
            ?? throw new FinanceException("Belum ada kamar aktif berkelas {$roomClass} untuk dijadikan acuan tarif.");

        $total = ($dailyRate * $estimatedDays) + $otherCharges;

        return DB::transaction(fn (): InpatientCostEstimate => InpatientCostEstimate::query()->create([
            'estimate_number' => $this->allocateNumber('EST'),
            'registration_id' => $kunjungan->id,
            'patient_id' => $kunjungan->patient_id,
            'patient_mrn' => $kunjungan->patient_mrn,
            'patient_name' => $kunjungan->patient_name,
            'payer_name' => $kunjungan->payer_name,
            'room_class' => $roomClass,
            'estimated_days' => $estimatedDays,
            'daily_rate' => $dailyRate,
            'other_charges' => $otherCharges,
            'total_estimate' => $total,
            'note' => $note,
            'prepared_at' => now(),
            'prepared_by' => $actor?->id,
            'prepared_by_name' => $actor?->name,
        ]));
    }

    private function allocateNumber(string $prefix): string
    {
        $key = $prefix . now()->format('Ymd');

        $row = DB::selectOne(
            'INSERT INTO finance.number_sequences (prefix, last_number, updated_at)
             VALUES (?, 1, now())
             ON CONFLICT (prefix) DO UPDATE
                SET last_number = finance.number_sequences.last_number + 1,
                    updated_at  = now()
             RETURNING last_number',
            [$key]
        );

        return $key . '-' . str_pad((string) $row->last_number, 5, '0', STR_PAD_LEFT);
    }
}
