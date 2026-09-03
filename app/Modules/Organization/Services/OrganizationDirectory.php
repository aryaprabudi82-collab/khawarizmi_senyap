<?php

namespace App\Modules\Organization\Services;

use App\Modules\Organization\Models\PracticeSchedule;
use App\Modules\Organization\Models\Practitioner;
use App\Modules\Organization\Models\Unit;
use DateTimeInterface;
use Illuminate\Support\Collection;

/**
 * Pintu masuk konteks organization.
 *
 * Konteks lain memanggil kelas ini alih-alih menyentuh organization.units atau
 * organization.practitioners langsung.
 */
class OrganizationDirectory
{
    public function findUnit(int $id): ?Unit
    {
        return Unit::query()->find($id);
    }

    public function findUnitByCode(string $code): ?Unit
    {
        return Unit::query()->where('code', $code)->first();
    }

    /** @return Collection<int, Unit> */
    public function activeUnits(?string $kind = null): Collection
    {
        return Unit::query()
            ->where('is_active', true)
            ->when($kind, fn ($q) => $q->where('kind', $kind))
            ->orderBy('name')
            ->get();
    }

    public function findPractitioner(int $id): ?Practitioner
    {
        return Practitioner::query()->find($id);
    }

    /**
     * Praktisi aktif tanpa syarat jadwal praktik mingguan — dipakai memilih
     * operator/dokter untuk booking_operasi dan konteks lain yang butuh
     * daftar praktisi tanpa terikat tanggal tertentu, pola sama dengan
     * inpatient.OrganizationContext::practitioners() untuk DPJP.
     *
     * @return Collection<int, Practitioner>
     */
    public function activePractitioners(): Collection
    {
        return Practitioner::query()->where('is_active', true)->orderBy('name')->get();
    }

    /** Praktisi yang boleh melayani pada tanggal tertentu, opsional per unit. */
    public function practitionersServingOn(DateTimeInterface $date, ?int $unitId = null): Collection
    {
        return Practitioner::query()
            ->servingOn($date)
            ->when($unitId, fn ($q) => $q->whereHas('units', fn ($u) => $u->where('organization.units.id', $unitId)))
            ->orderBy('name')
            ->get();
    }

    /** Apakah praktisi ini boleh dijadikan DPJP pada tanggal tersebut. */
    public function practitionerIsServing(int $practitionerId, DateTimeInterface $date): bool
    {
        return Practitioner::query()
            ->whereKey($practitionerId)
            ->servingOn($date)
            ->exists();
    }

    /**
     * Jadwal praktik praktisi pada hari tertentu — dipakai booking_registrasi/
     * booking_periksa untuk memvalidasi tanggal yang dipilih terhadap jadwal
     * mingguan sungguhan, bukan cuma masa aktif SIP.
     *
     * @return Collection<int, PracticeSchedule>
     */
    public function scheduledOn(int $practitionerId, DateTimeInterface $date, ?int $unitId = null): Collection
    {
        return PracticeSchedule::query()
            ->where('practitioner_id', $practitionerId)
            ->where('day_of_week', (int) $date->format('N'))
            ->where('is_active', true)
            ->when($unitId, fn ($q) => $q->where('unit_id', $unitId))
            ->get();
    }
}
