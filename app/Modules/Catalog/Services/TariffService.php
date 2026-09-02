<?php

namespace App\Modules\Catalog\Services;

use App\Modules\Catalog\Models\Payer;
use App\Modules\Catalog\Models\Service;
use App\Modules\Catalog\Models\Tariff;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Pengelolaan layanan, penjamin, dan tarif — sisi tulis konteks catalog.
 *
 * Terpisah dari TariffLookup (sisi baca yang dipakai encounter/order/pharmacy
 * lintas konteks) karena keduanya melayani pemanggil yang berbeda: ini dipakai
 * admin data master, TariffLookup dipakai proses transaksi.
 */
class TariffService
{
    public function createPayer(array $data): Payer
    {
        return Payer::query()->create($data);
    }

    public function updatePayer(Payer $payer, array $data): Payer
    {
        $payer->update($data);

        return $payer->refresh();
    }

    public function createService(array $data): Service
    {
        return Service::query()->create($data);
    }

    public function updateService(Service $service, array $data): Service
    {
        $service->update($data);

        return $service->refresh();
    }

    /**
     * Menetapkan tarif baru untuk satu kombinasi layanan-penjamin-kelas.
     *
     * Tarif lama tidak pernah ditimpa: kalau ada baris aktif untuk kombinasi
     * yang sama, ia ditutup (valid_until = sehari sebelum tarif baru berlaku)
     * lebih dulu, baru baris baru dibuka. Kunjungan bulan lalu tetap bisa
     * dihitung ulang dengan tarif yang berlaku saat itu.
     */
    public function setRate(
        int $serviceId,
        int $payerId,
        string $careClass,
        float $amount,
        ?float $amountReturning,
        string $validFrom,
    ): Tariff {
        return DB::transaction(function () use ($serviceId, $payerId, $careClass, $amount, $amountReturning, $validFrom): Tariff {
            $berjalan = Tariff::query()
                ->where('service_id', $serviceId)
                ->where('payer_id', $payerId)
                ->where('care_class', $careClass)
                ->whereNull('valid_until')
                ->first();

            if ($berjalan !== null) {
                if ($berjalan->valid_from->toDateString() >= $validFrom) {
                    throw new RuntimeException(
                        'Tarif berjalan mulai berlaku ' . $berjalan->valid_from->format('d-m-Y')
                        . ', tidak boleh ditutup oleh tarif baru yang tanggal mulainya sama atau lebih awal.'
                    );
                }

                $berjalan->update([
                    'valid_until' => date('Y-m-d', strtotime($validFrom . ' -1 day')),
                ]);
            }

            return Tariff::query()->create([
                'service_id' => $serviceId,
                'payer_id' => $payerId,
                'care_class' => $careClass,
                'amount' => $amount,
                'amount_returning' => $amountReturning,
                'valid_from' => $validFrom,
                'valid_until' => null,
            ]);
        });
    }

    public function endRate(Tariff $tariff, string $validUntil): Tariff
    {
        $tariff->update(['valid_until' => $validUntil]);

        return $tariff->refresh();
    }
}
