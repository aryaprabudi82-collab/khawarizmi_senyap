<?php

namespace App\Modules\Platform\Http\Controllers;

use App\Modules\Platform\Models\Permission;
use App\Modules\Platform\Models\Role;
use App\Modules\Platform\Services\AuditLogger;
use App\Modules\Platform\Services\ManagedPermissionCatalog;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Kelola peran custom yang dibatasi ke modul tertentu.
 *
 * Peran bawaan (is_system=true, didefinisikan di database/data/roles.json)
 * sengaja tidak bisa diubah lewat sini — RoleSeeder menyamakan ulang
 * permission-nya dari roles.json tiap kali seeder jalan, jadi perubahan lewat
 * layar ini akan hilang begitu saja pada seed berikutnya. Peran custom yang
 * dibuat di sini tidak disentuh RoleSeeder sama sekali (loop-nya hanya
 * mengiterasi definisi dari roles.json), jadi aman dikelola penuh lewat UI.
 */
class RoleController
{
    public function __construct(
        private readonly ManagedPermissionCatalog $catalog,
        private readonly AuditLogger $audit,
    ) {}

    public function index(): View
    {
        return view('platform::peran.index', [
            'peran' => Role::query()->withCount(['users', 'permissions'])->orderBy('name')->get(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'max:60', 'alpha_dash', Rule::unique(Role::class, 'code')],
            'name' => ['required', 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:255'],
        ], [], ['code' => 'kode', 'name' => 'nama', 'description' => 'deskripsi']);

        $role = Role::query()->create($data + ['is_system' => false]);
        $this->audit->log($request, 'platform', 'buat_peran', $role);

        return redirect()->route('platform.peran.show', $role)
            ->with('sukses', "Peran {$role->name} dibuat. Pilih modul yang boleh diakses peran ini di bawah.");
    }

    public function show(Role $peran): View
    {
        return view('platform::peran.show', [
            'peran' => $peran->load('users'),
            'kelompok' => $this->catalog->grouped(),
            'kodeDimiliki' => $peran->permissions()->pluck('code')->all(),
        ]);
    }

    public function update(Request $request, Role $peran): RedirectResponse
    {
        abort_if($peran->is_system, 403, 'Peran bawaan sistem dikelola lewat berkas konfigurasi, tidak bisa diubah lewat layar ini.');

        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:255'],
            'permissions' => ['array'],
            'permissions.*' => ['string', Rule::exists(Permission::class, 'code')],
        ], [], ['name' => 'nama', 'description' => 'deskripsi', 'permissions' => 'modul']);

        $peran->update(['name' => $data['name'], 'description' => $data['description'] ?? null]);
        $peran->syncPermissionCodes($data['permissions'] ?? []);

        $this->audit->log($request, 'platform', 'perbarui_peran', $peran, ['permissions' => $data['permissions'] ?? []]);

        return back()->with('sukses', "Peran {$peran->name} diperbarui.");
    }

    public function destroy(Request $request, Role $peran): RedirectResponse
    {
        abort_if($peran->is_system, 403, 'Peran bawaan sistem tidak bisa dihapus.');
        abort_if($peran->users()->exists(), 422, 'Peran ini masih dipakai pengguna — lepaskan dulu dari pengguna sebelum menghapus.');

        $nama = $peran->name;
        $kode = $peran->code;
        $peran->delete();

        $this->audit->log($request, 'platform', 'hapus_peran', null, ['role_code' => $kode]);

        return redirect()->route('platform.peran.index')->with('sukses', "Peran {$nama} dihapus.");
    }
}
