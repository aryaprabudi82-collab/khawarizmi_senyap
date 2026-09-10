<?php

namespace App\Modules\Organization\Services;

use App\Modules\Organization\Models\OperatingRoom;
use App\Modules\Organization\Models\PracticeSchedule;
use App\Modules\Organization\Models\Practitioner;
use App\Modules\Organization\Models\Unit;
use App\Modules\Organization\Models\UnitSupervisor;
use DateTimeInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

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
     * Menetapkan penanggung jawab sebuah unit penunjang.
     *
     * Penugasan yang masih terbuka DITUTUP dulu — otomatis, dan pada hari
     * sebelum yang baru mulai. Kalau tidak, dua orang tercatat menjabat
     * bersamaan, dan itu bukan kelonggaran administratif: ia berarti tidak
     * ada yang tahu tanda tangan siapa yang sah pada hasil pemeriksaan.
     *
     * Berbeda dari `set_pjlab` Khanza yang menimpa satu baris, penugasan
     * lama TIDAK hilang — hasil pemeriksaan lama harus tetap bisa
     * menemukan penanggung jawabnya pada tanggal pemeriksaan itu.
     */
    public function assignSupervisor(
        Unit $unit,
        Practitioner $practitioner,
        string $startDate,
        ?string $decreeNumber = null,
        ?string $note = null,
    ): UnitSupervisor {
        return DB::transaction(function () use ($unit, $practitioner, $startDate, $decreeNumber, $note) {
            $berjalan = UnitSupervisor::query()
                ->where('unit_id', $unit->getKey())
                ->whereNull('end_date')
                ->first();

            if ($berjalan !== null) {
                if ($berjalan->start_date->toDateString() > $startDate) {
                    throw new OrganizationException(
                        'Penanggung jawab baru tidak bisa mulai sebelum penugasan yang sedang berjalan.'
                    );
                }

                $berjalan->update([
                    'end_date' => Carbon::parse($startDate)->subDay()->toDateString(),
                ]);
            }

            return UnitSupervisor::query()->create([
                'unit_id' => $unit->getKey(),
                'practitioner_id' => $practitioner->getKey(),
                'start_date' => $startDate,
                'decree_number' => $decreeNumber,
                'note' => $note,
            ]);
        });
    }

    /**
     * Penanggung jawab sebuah unit pada satu tanggal.
     *
     * Menerima tanggal, bukan mengembalikan yang menjabat sekarang: yang
     * mencetak ulang hasil pemeriksaan lama butuh penanggung jawab pada
     * tanggal pemeriksaan itu. Inilah pertanyaan yang `set_pjlab` Khanza
     * tidak bisa jawab sama sekali.
     */
    public function supervisorOn(int $unitId, string $date): ?UnitSupervisor
    {
        return UnitSupervisor::query()
            ->with('practitioner')
            ->where('unit_id', $unitId)
            ->whereDate('start_date', '<=', $date)
            ->where(fn ($q) => $q->whereNull('end_date')->orWhereDate('end_date', '>=', $date))
            ->first();
    }

    /**
     * Unit penunjang aktif yang BELUM punya penanggung jawab.
     *
     * Daftar kejujuran: unit penunjang tanpa penanggung jawab bukan
     * keadaan yang sah menurut akreditasi, dan lebih baik ia terlihat
     * sebagai daftar pekerjaan daripada baru ketahuan saat diperiksa.
     *
     * @return Collection<int, Unit>
     */
    public function unitsWithoutSupervisor(): Collection
    {
        $sudah = UnitSupervisor::query()->whereNull('end_date')->pluck('unit_id');

        return Unit::query()
            ->where('is_active', true)
            ->whereIn('kind', ['penunjang', 'penunjang-medis'])
            ->whereNotIn('id', $sudah)
            ->orderBy('name')
            ->get();
    }

    /**
     * Ruang operasi aktif, untuk daftar pilihan di clinical dan encounter.
     *
     * KEDUA KONTEKS ITU SEBELUMNYA MENGETIK NAMANYA BEBAS, dan laporan RL
     * mengelompokkan utilisasi kamar operasi berdasarkan teks itu — "OK 1"
     * dan "OK1" terhitung dua ruang berbeda pada laporan wajib, tanpa satu
     * pun galat muncul.
     *
     * @return Collection<int, OperatingRoom>
     */
    public function activeOperatingRooms(): Collection
    {
        return OperatingRoom::query()
            ->where('is_active', true)
            ->orderBy('code')
            ->get();
    }

    public function findOperatingRoomByCode(string $code): ?OperatingRoom
    {
        return OperatingRoom::query()->where('code', $code)->first();
    }

    /**
     * Apakah kode ruang operasi ini sah dan masih dipakai.
     *
     * Dipakai memvalidasi isian di konteks lain. Ruang yang sudah
     * dinonaktifkan ditolak untuk pencatatan BARU, tapi baris lama yang
     * sudah menunjuknya tetap terbaca — riwayat operasi di ruang yang
     * sekarang ditutup tidak boleh lenyap dari laporan.
     */
    public function operatingRoomIsUsable(?string $code): bool
    {
        if ($code === null || $code === '') {
            return true;
        }

        return OperatingRoom::query()->where('code', $code)->where('is_active', true)->exists();
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
