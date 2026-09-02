<?php

namespace App\Modules\Platform\Http\Controllers;

use App\Modules\Platform\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class AuthController
{
    public function form(): View
    {
        return view('platform::auth.login');
    }

    public function login(Request $request): RedirectResponse
    {
        $kredensial = $request->validate([
            'username' => ['required', 'string', 'max:60'],
            'password' => ['required', 'string'],
        ], [], [
            'username' => 'nama pengguna',
            'password' => 'kata sandi',
        ]);

        if (! Auth::attempt($kredensial, $request->boolean('ingat_saya'))) {
            throw ValidationException::withMessages([
                'username' => 'Nama pengguna atau kata sandi tidak cocok.',
            ]);
        }

        /** @var User $user */
        $user = Auth::user();

        // Akun nonaktif tetap ada datanya, tapi tidak boleh masuk.
        if (! $user->is_active) {
            Auth::logout();

            throw ValidationException::withMessages([
                'username' => 'Akun ini sedang dinonaktifkan. Hubungi administrator sistem.',
            ]);
        }

        $request->session()->regenerate();

        $user->forceFill([
            'last_login_at' => now(),
            'last_login_ip' => $request->ip(),
        ])->save();

        $this->catatAudit('login', $user, $request);

        return redirect()->intended(route('beranda'));
    }

    public function logout(Request $request): RedirectResponse
    {
        $user = Auth::user();

        if ($user !== null) {
            $this->catatAudit('logout', $user, $request);
        }

        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('masuk');
    }

    /**
     * Jejak audit masuk dan keluar.
     *
     * Permenkes 24/2022 mensyaratkan rekam medis elektronik punya jejak audit;
     * siapa mengakses kapan adalah bagian paling dasar dari itu.
     */
    private function catatAudit(string $aksi, User $user, Request $request): void
    {
        DB::table('platform.audit_logs')->insert([
            'created_at' => now(),
            'user_id' => $user->id,
            'username' => $user->username,
            'context' => 'platform',
            'action' => $aksi,
            'subject_type' => User::class,
            'subject_id' => (string) $user->id,
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
        ]);
    }
}
