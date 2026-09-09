<?php

namespace App\Modules\Organization\Http\Controllers;

use App\Modules\Organization\Models\OperatingRoom;
use App\Modules\Organization\Models\PracticeSchedule;
use App\Modules\Organization\Models\Practitioner;
use App\Modules\Organization\Models\Unit;
use App\Modules\Organization\Services\OrganizationAdminService;
use App\Modules\Organization\Services\OrganizationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class MasterDataController
{
    public function __construct(private readonly OrganizationAdminService $admin) {}

    public function index(): View
    {
        return view('organization::master.index', [
            'unit' => Unit::query()->orderBy('kind')->orderBy('name')->get(),
            'praktisi' => Practitioner::query()->with(['units', 'schedules.unit'])->orderBy('name')->get(),
            'unitAktif' => Unit::query()->where('is_active', true)->orderBy('name')->get(),
        ]);
    }

    public function storeUnit(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'max:20', Rule::unique(Unit::class, 'code')],
            'name' => ['required', 'string', 'max:150'],
            'kind' => ['required', 'in:poliklinik,igd,rawat-inap,penunjang,penunjang-medis'],
            'daily_quota' => ['nullable', 'integer', 'min:1'],
        ], [], ['code' => 'kode', 'name' => 'nama', 'kind' => 'jenis', 'daily_quota' => 'kuota harian']);

        $this->admin->createUnit($data + ['is_active' => true]);

        return back()->with('sukses', "Unit {$data['name']} ditambahkan.");
    }

    public function updateUnit(Request $request, Unit $unit): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:150'],
            'kind' => ['required', 'in:poliklinik,igd,rawat-inap,penunjang,penunjang-medis'],
            'daily_quota' => ['nullable', 'integer', 'min:1'],
            'is_active' => ['nullable', 'boolean'],
        ], [], ['name' => 'nama', 'kind' => 'jenis', 'daily_quota' => 'kuota harian']);

        $data['is_active'] = $request->boolean('is_active');
        $data['daily_quota'] = $data['daily_quota'] ?? null;

        $this->admin->updateUnit($unit, $data);

        return back()->with('sukses', "Unit {$unit->name} diperbarui.");
    }

    public function storePractitioner(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'max:30', Rule::unique(Practitioner::class, 'code')],
            'name' => ['required', 'string', 'max:150'],
            'title' => ['nullable', 'string', 'max:60'],
            'specialty' => ['nullable', 'string', 'max:100'],
            'sip_number' => ['nullable', 'string', 'max:120'],
            'phone' => ['nullable', 'string', 'max:40'],
            'email' => ['nullable', 'email', 'max:150'],
            'unit_id' => ['required', 'integer', Rule::exists(Unit::class, 'id')],
            'active_from' => ['nullable', 'date'],
        ], [], [
            'code' => 'kode', 'name' => 'nama', 'unit_id' => 'unit utama',
        ]);

        $unitId = $data['unit_id'];
        unset($data['unit_id']);

        $this->admin->createPractitioner($data + [
            'is_active' => true,
            'active_from' => $data['active_from'] ?? now()->toDateString(),
        ], $unitId);

        return back()->with('sukses', "Praktisi {$data['name']} ditambahkan.");
    }

    public function updatePractitioner(Request $request, Practitioner $praktisi): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:150'],
            'title' => ['nullable', 'string', 'max:60'],
            'specialty' => ['nullable', 'string', 'max:100'],
            'sip_number' => ['nullable', 'string', 'max:120'],
            'phone' => ['nullable', 'string', 'max:40'],
            'email' => ['nullable', 'email', 'max:150'],
            'is_active' => ['nullable', 'boolean'],
            'active_until' => ['nullable', 'date'],
            'units' => ['nullable', 'array'],
            'units.*' => ['integer', Rule::exists(Unit::class, 'id')],
            'primary_unit_id' => ['nullable', 'integer'],
        ], [], ['name' => 'nama']);

        $unitIds = $data['units'] ?? [];
        $primary = $data['primary_unit_id'] ?? null;
        unset($data['units'], $data['primary_unit_id']);

        $data['is_active'] = $request->boolean('is_active');

        $this->admin->updatePractitioner($praktisi, $data);
        $this->admin->syncUnits($praktisi, $unitIds, $primary);

        return back()->with('sukses', "Praktisi {$praktisi->name} diperbarui.");
    }

    public function storeSchedule(Request $request, Practitioner $praktisi): RedirectResponse
    {
        $data = $request->validate([
            'unit_id' => ['required', 'integer', Rule::exists(Unit::class, 'id')],
            'day_of_week' => ['required', 'integer', 'between:1,7'],
            'start_time' => ['required', 'date_format:H:i'],
            'end_time' => ['required', 'date_format:H:i', 'after:start_time'],
            'note' => ['nullable', 'string', 'max:255'],
        ], [], ['unit_id' => 'unit', 'day_of_week' => 'hari', 'start_time' => 'jam mulai', 'end_time' => 'jam selesai']);

        try {
            $this->admin->addSchedule($praktisi, $data);
        } catch (OrganizationException $e) {
            return back()->with('galat', $e->getMessage());
        }

        return back()->with('sukses', "Jadwal praktik {$praktisi->displayName()} ditambahkan.");
    }

    public function destroySchedule(PracticeSchedule $jadwal): RedirectResponse
    {
        $this->admin->removeSchedule($jadwal);

        return back()->with('sukses', 'Jadwal praktik dihapus.');
    }

    /**
     * Master ruang operasi (Khanza `ruang_ok`, domain U).
     *
     * Menutup cacat yang sudah merusak laporan wajib: nama ruang operasi
     * sebelumnya diketik bebas di dua konteks sekaligus, sementara laporan
     * RL mengelompokkan berdasarkan teks itu.
     */
    public function operatingRooms(): View
    {
        return view('organization::master.ruang-operasi', [
            'ruang' => OperatingRoom::query()->with('unit')->orderBy('code')->get(),
            'unitAktif' => Unit::query()->where('is_active', true)->orderBy('name')->get(),
        ]);
    }

    public function storeOperatingRoom(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'max:20', Rule::unique(OperatingRoom::class, 'code')],
            'name' => ['required', 'string', 'max:60'],
            'unit_id' => ['nullable', 'integer', Rule::exists(Unit::class, 'id')],
            'note' => ['nullable', 'string', 'max:255'],
        ], [], ['code' => 'kode', 'name' => 'nama', 'unit_id' => 'unit']);

        OperatingRoom::query()->create($data + ['is_active' => true]);

        return back()->with('sukses', "Ruang operasi {$data['name']} ditambahkan.");
    }

    public function updateOperatingRoom(Request $request, OperatingRoom $ruang): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:60'],
            'unit_id' => ['nullable', 'integer', Rule::exists(Unit::class, 'id')],
            'note' => ['nullable', 'string', 'max:255'],
            'is_active' => ['nullable', 'boolean'],
        ], [], ['name' => 'nama', 'unit_id' => 'unit']);

        /*
         * KODE TIDAK BISA DIUBAH. Operasi dan jadwal yang sudah tercatat
         * menunjuk ruang lewat kodenya; mengubah kode berarti seluruh
         * riwayat menunjuk ruang yang tidak ada lagi — dan laporan
         * utilisasinya berhenti di tanggal perubahan tanpa ada yang
         * memberi tahu. Ruang yang salah kode dinonaktifkan, lalu yang
         * benar dibuat baru.
         */
        $ruang->update($data + ['is_active' => (bool) $request->boolean('is_active')]);

        return back()->with('sukses', "Ruang operasi {$ruang->name} diperbarui.");
    }
}
