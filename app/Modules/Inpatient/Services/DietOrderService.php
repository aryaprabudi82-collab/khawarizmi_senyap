<?php

namespace App\Modules\Inpatient\Services;

use App\Modules\Inpatient\Models\Admission;
use App\Modules\Inpatient\Models\DietOrder;
use App\Modules\Platform\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Order diet pasien ranap — lihat catatan migrasi diet_orders.
 * Satu admisi hanya boleh punya satu order aktif; order baru otomatis
 * menutup yang lama, pola sama dengan EmployeeHistoryService::recordPositionChange.
 */
class DietOrderService
{
    public function order(Admission $admission, array $data, User $orderedBy): DietOrder
    {
        if ($admission->status !== Admission::STATUS_DIRAWAT) {
            throw new InpatientException("Admisi {$admission->admission_number} sudah tidak dirawat, tidak bisa mencatat order diet baru.");
        }

        return DB::transaction(function () use ($admission, $data, $orderedBy) {
            $admission->activeDietOrder?->update([
                'status' => DietOrder::STATUS_DIHENTIKAN,
                'end_date' => $data['start_date'],
            ]);

            return $admission->dietOrders()->create([
                'diet_type' => $data['diet_type'],
                'note' => $data['note'] ?? null,
                'start_date' => $data['start_date'],
                'status' => DietOrder::STATUS_AKTIF,
                'ordered_by' => $orderedBy->id,
                'ordered_by_name' => $orderedBy->name,
            ]);
        });
    }

    public function stop(DietOrder $order): DietOrder
    {
        if ($order->status !== DietOrder::STATUS_AKTIF) {
            throw new InpatientException('Order diet ini sudah dihentikan.');
        }

        $order->update(['status' => DietOrder::STATUS_DIHENTIKAN, 'end_date' => now()->toDateString()]);

        return $order->refresh();
    }
}
