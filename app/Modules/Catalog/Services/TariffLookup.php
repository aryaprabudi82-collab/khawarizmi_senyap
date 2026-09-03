<?php

namespace App\Modules\Catalog\Services;

use App\Modules\Catalog\Models\Payer;
use App\Modules\Catalog\Models\Service;
use App\Modules\Catalog\Models\Tariff;
use DateTimeInterface;
use Illuminate\Support\Collection;

/**
 * Pintu masuk konteks catalog.
 */
class TariffLookup
{
    public function findPayer(int $id): ?Payer
    {
        return Payer::query()->find($id);
    }

    public function findPayerByCode(string $code): ?Payer
    {
        return Payer::query()->where('code', $code)->first();
    }

    /**
     * Tarif yang berlaku pada satu tanggal.
     *
     * Tanggal ikut dipertimbangkan supaya kunjungan lama tetap bisa dihitung
     * ulang dengan tarif yang berlaku saat itu, bukan dengan tarif hari ini.
     */
    public function resolve(
        string $serviceCode,
        int $payerId,
        DateTimeInterface $on,
        string $careClass = '-',
        bool $returningPatient = false,
    ): ?float {
        $date = $on->format('Y-m-d');

        $tariff = Tariff::query()
            ->join('catalog.services as s', 's.id', '=', 'catalog.tariffs.service_id')
            ->where('s.code', $serviceCode)
            ->where('catalog.tariffs.payer_id', $payerId)
            ->where('catalog.tariffs.care_class', $careClass)
            ->where('catalog.tariffs.valid_from', '<=', $date)
            ->where(fn ($q) => $q->whereNull('catalog.tariffs.valid_until')
                ->orWhere('catalog.tariffs.valid_until', '>=', $date))
            ->orderByDesc('catalog.tariffs.valid_from')
            ->select('catalog.tariffs.*')
            ->first();

        if ($tariff === null) {
            return null;
        }

        if ($returningPatient && $tariff->amount_returning !== null) {
            return (float) $tariff->amount_returning;
        }

        return (float) $tariff->amount;
    }

    public function findServiceByCode(string $code): ?Service
    {
        return Service::query()->where('code', $code)->first();
    }

    /** @return Collection<int, Service> */
    public function servicesByCategory(string $category): Collection
    {
        return Service::query()
            ->where('category', $category)
            ->where('is_active', true)
            ->orderBy('name')
            ->get();
    }
}
