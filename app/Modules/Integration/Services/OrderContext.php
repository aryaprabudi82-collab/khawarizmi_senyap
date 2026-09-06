<?php

namespace App\Modules\Integration\Services;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use stdClass;

/**
 * Pembaca hasil penunjang milik konteks orders, lewat orders.v_order_result
 * — kontrak yang diterbitkan konteks orders, bukan tabelnya.
 *
 * HANYA HASIL YANG SUDAH DIVERIFIKASI yang boleh diambil untuk dikirim.
 * Hasil yang belum diverifikasi masih bisa berubah, dan hasil yang sudah
 * tersebar nasional lalu berubah jauh lebih sulit ditarik kembali daripada
 * hasil yang dikirim terlambat. Penyaringnya diletakkan di sini, di satu
 * tempat, supaya tidak ada alur kirim yang bisa melewatinya karena lupa.
 */
class OrderContext
{
    private const VIEW = 'orders.v_order_result';

    /** Butir-butir satu permintaan yang hasilnya sudah diverifikasi. */
    public function verifiedResultsFor(int $orderId): Collection
    {
        return DB::table(self::VIEW)
            ->where('order_id', $orderId)
            ->whereNotNull('verified_at')
            ->orderBy('result_id')
            ->get();
    }

    /** Kepala permintaannya — satu baris, diambil dari butir mana pun. */
    public function orderHeader(int $orderId): ?stdClass
    {
        return DB::table(self::VIEW)
            ->where('order_id', $orderId)
            ->orderBy('result_id')
            ->first();
    }

    /**
     * Jenis spesimen yang benar-benar dipakai satu permintaan.
     *
     * Kosong berarti pemeriksaannya memang tidak mengambil bahan dari
     * pasien — radiologi bekerja dengan modalitas, bukan spesimen.
     *
     * @return Collection<int, string>
     */
    public function specimenTypesFor(int $orderId): Collection
    {
        return DB::table(self::VIEW)
            ->where('order_id', $orderId)
            ->whereNotNull('specimen_type')
            ->where('specimen_type', '<>', '')
            ->distinct()
            ->orderBy('specimen_type')
            ->pluck('specimen_type');
    }

    /** Permintaan yang sudah terverifikasi tapi belum pernah dikirim. */
    public function pendingVerified(string $category, int $limit = 50): Collection
    {
        return DB::table(self::VIEW . ' as r')
            ->whereNotNull('r.verified_at')
            ->where('r.category', $category)
            ->whereNotExists(function ($q) {
                $q->select(DB::raw(1))
                    ->from('integration.identity_mappings as m')
                    ->where('m.target_system', 'satusehat')
                    ->where('m.resource_type', 'diagnosticreport')
                    ->where('m.source_context', 'orders')
                    ->whereColumn('m.source_id', 'r.order_id');
            })
            ->groupBy('r.order_id', 'r.order_number', 'r.patient_name', 'r.patient_mrn', 'r.verified_at')
            ->selectRaw('r.order_id, r.order_number, r.patient_name, r.patient_mrn, r.verified_at,
                         count(*) AS butir')
            ->orderBy('r.verified_at')
            ->limit($limit)
            ->get();
    }
}
