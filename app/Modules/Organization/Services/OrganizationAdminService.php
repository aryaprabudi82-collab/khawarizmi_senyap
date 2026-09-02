<?php

namespace App\Modules\Organization\Services;

use App\Modules\Organization\Models\Practitioner;
use App\Modules\Organization\Models\Unit;

/**
 * Pengelolaan unit dan praktisi — sisi tulis konteks organization.
 *
 * Terpisah dari OrganizationDirectory (sisi baca yang dipakai encounter/
 * clinical/order lintas konteks) karena keduanya melayani pemanggil yang
 * berbeda: ini dipakai admin data master, OrganizationDirectory dipakai
 * proses transaksi.
 */
class OrganizationAdminService
{
    public function createUnit(array $data): Unit
    {
        return Unit::query()->create($data);
    }

    public function updateUnit(Unit $unit, array $data): Unit
    {
        $unit->update($data);

        return $unit->refresh();
    }

    public function createPractitioner(array $data, ?int $unitId = null): Practitioner
    {
        $practitioner = Practitioner::query()->create($data);

        if ($unitId !== null) {
            $practitioner->units()->attach($unitId, ['is_primary' => true]);
        }

        return $practitioner;
    }

    public function updatePractitioner(Practitioner $practitioner, array $data): Practitioner
    {
        $practitioner->update($data);

        return $practitioner->refresh();
    }

    /** Menyamakan unit tempat praktisi bertugas dengan daftar yang diberikan. */
    public function syncUnits(Practitioner $practitioner, array $unitIds, ?int $primaryUnitId = null): void
    {
        $sync = [];

        foreach ($unitIds as $id) {
            $sync[$id] = ['is_primary' => $id === $primaryUnitId];
        }

        $practitioner->units()->sync($sync);
    }
}
