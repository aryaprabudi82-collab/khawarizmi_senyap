<?php

namespace App\Modules\Platform\Http\Controllers;

use App\Modules\Platform\Models\Role;
use App\Modules\Platform\Models\User;
use App\Modules\Platform\Services\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Kelola pengguna: siapa bisa masuk, dan lewat peran mana dia mendapat akses.
 *
 * Pemberian akses per modul ditentukan lewat peran (lihat RoleController),
 * bukan di sini — layar ini hanya menghubungkan pengguna ke satu/lebih peran
 * yang sudah punya cakupan modulnya sendiri.
 */
class UserController
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function index(): View
    {
        return view('platform::pengguna.index', [
            'pengguna' => User::query()->with('roles')->orderBy('name')->get(),
            'peran' => Role::query()->orderBy('name')->get(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'username' => ['required', 'string', 'max:60', 'alpha_dash', Rule::unique(User::class, 'username')],
            'name' => ['required', 'string', 'max:150'],
            'email' => ['nullable', 'email', 'max:150', Rule::unique(User::class, 'email')],
            'nip' => ['nullable', 'string', 'max:30', Rule::unique(User::class, 'nip')],
            'password' => ['required', 'string', 'min:8'],
            'roles' => ['required', 'array', 'min:1'],
            'roles.*' => ['integer', Rule::exists(Role::class, 'id')],
        ], [], [
            'username' => 'nama pengguna', 'name' => 'nama', 'email' => 'surel', 'nip' => 'NIP',
            'password' => 'kata sandi', 'roles' => 'peran',
        ]);

        $user = User::query()->create([
            'username' => $data['username'],
            'name' => $data['name'],
            'email' => $data['email'] ?? null,
            'nip' => $data['nip'] ?? null,
            'password' => $data['password'],
            'is_active' => true,
            // Akun baru wajib ganti sandi sendiri — admin tidak seharusnya tahu
            // kata sandi tetap milik pengguna lain setelah baris ini disimpan.
            'must_change_password' => true,
        ]);

        $user->roles()->sync($data['roles']);
        $this->audit->log($request, 'platform', 'buat_pengguna', $user, ['roles' => $data['roles']]);

        return back()->with('sukses', "Pengguna {$user->username} ditambahkan.");
    }

    public function update(Request $request, User $pengguna): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:150'],
            'email' => ['nullable', 'email', 'max:150', Rule::unique(User::class, 'email')->ignore($pengguna->id)],
            'nip' => ['nullable', 'string', 'max:30', Rule::unique(User::class, 'nip')->ignore($pengguna->id)],
            'roles' => ['required', 'array', 'min:1'],
            'roles.*' => ['integer', Rule::exists(Role::class, 'id')],
        ], [], ['name' => 'nama', 'email' => 'surel', 'nip' => 'NIP', 'roles' => 'peran']);

        $pengguna->update([
            'name' => $data['name'],
            'email' => $data['email'] ?? null,
            'nip' => $data['nip'] ?? null,
        ]);

        $pengguna->roles()->sync($data['roles']);
        $this->audit->log($request, 'platform', 'perbarui_pengguna', $pengguna, ['roles' => $data['roles']]);

        return back()->with('sukses', "Pengguna {$pengguna->username} diperbarui.");
    }

    public function resetPassword(Request $request, User $pengguna): RedirectResponse
    {
        $data = $request->validate(['password' => ['required', 'string', 'min:8']], [], ['password' => 'kata sandi']);

        $pengguna->update(['password' => $data['password'], 'must_change_password' => true]);
        $this->audit->log($request, 'platform', 'reset_sandi_pengguna', $pengguna);

        return back()->with('sukses', "Kata sandi {$pengguna->username} direset. Pengguna wajib menggantinya saat masuk berikutnya.");
    }

    public function setActive(Request $request, User $pengguna, string $status): RedirectResponse
    {
        abort_unless(in_array($status, ['aktifkan', 'nonaktifkan'], true), 404);

        $aktif = $status === 'aktifkan';

        // Mencegah admin mengunci diri sendiri keluar dari layar ini.
        abort_if(! $aktif && $pengguna->id === $request->user()?->id, 422, 'Tidak bisa menonaktifkan akun sendiri.');

        $pengguna->update(['is_active' => $aktif]);
        $this->audit->log($request, 'platform', $aktif ? 'aktifkan_pengguna' : 'nonaktifkan_pengguna', $pengguna);

        return back()->with('sukses', $aktif
            ? "Pengguna {$pengguna->username} diaktifkan kembali."
            : "Pengguna {$pengguna->username} dinonaktifkan.");
    }
}
