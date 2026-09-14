<?php

namespace App\Modules\Platform\Http\Controllers;

use App\Modules\Platform\Models\Role;
use App\Modules\Platform\Models\User;
use App\Modules\Platform\Services\LdapAuthenticator;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class AuthController
{
    public function __construct(private readonly LdapAuthenticator $ldap) {}

    public function form(): View
    {
        return view('platform::auth.login');
    }

    /**
     * DUA JALUR MASUK, DAN URUTANNYA MENENTUKAN.
     *
     *   1. Active Directory — bila dihidupkan
     *   2. Kata sandi lokal — selalu, sebagai cadangan
     *
     * Jalur 1 yang GAGAL jatuh ke jalur 2, bukan menolak. Itu yang membuat
     * server AD mati tidak mengunci seluruh rumah sakit, dan yang membuat
     * akun darurat tetap bisa dipakai saat jaringan RS terputus. Ditukar
     * urutannya — lokal dulu, AD belakangan — akun pegawai yang kata
     * sandinya pernah diubah di AD akan tetap bisa masuk dengan kata sandi
     * lama yang tertinggal di basis data ini, dan itu persis yang hendak
     * dihindari dengan memakai AD.
     */
    public function login(Request $request): RedirectResponse
    {
        $kredensial = $request->validate([
            'username' => ['required', 'string', 'max:60'],
            'password' => ['required', 'string'],
        ], [], [
            'username' => 'nama pengguna',
            'password' => 'kata sandi',
        ]);

        $lewatAd = false;
        $user = $this->masukLewatAd($kredensial['username'], $kredensial['password'], $request);

        if ($user !== null) {
            $lewatAd = true;
            Auth::login($user, $request->boolean('ingat_saya'));
        } elseif (! Auth::attempt($kredensial, $request->boolean('ingat_saya'))) {
            throw ValidationException::withMessages([
                'username' => 'Nama pengguna atau kata sandi tidak cocok.',
            ]);
        }

        /** @var User $user */
        $user = Auth::user();

        /*
         * Akun nonaktif tetap ada datanya, tapi tidak boleh masuk — dan
         * pemeriksaan ini berlaku untuk KEDUA jalur. Penonaktifan di sistem
         * ini mengalahkan keanggotaan grup di AD: seorang pegawai bisa saja
         * masih terdaftar di direktori sementara aksesnya ke SIMRS sudah
         * dicabut, dan yang memutuskan hal itu adalah administrator di sini.
         */
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

        // Jalurnya dibedakan di jejak audit. Tanpa itu, tidak ada cara
        // mengetahui apakah sebuah akun masuk lewat AD atau lewat kata sandi
        // lokal yang seharusnya sudah tidak dipakai lagi.
        $this->catatAudit($lewatAd ? 'login_ldap' : 'login_lokal', $user, $request);

        return redirect()->intended(route('beranda'));
    }

    /**
     * Mencoba Active Directory, lalu menyiapkan akun lokalnya.
     *
     * Mengembalikan null bila AD dimatikan, kredensialnya salah, penggunanya
     * bukan anggota grup yang diizinkan, atau servernya tak terjangkau —
     * pemanggil memperlakukan keempatnya sama: lanjut ke kata sandi lokal.
     */
    private function masukLewatAd(string $username, string $password, Request $request): ?User
    {
        $identitas = $this->ldap->coba($username, $password);

        if ($identitas === null) {
            return null;
        }

        $user = User::query()->where('username', $identitas['username'])->first();

        if ($user !== null) {
            /*
             * Nama dan surel disinkronkan tiap masuk; PERAN TIDAK DISENTUH.
             * Administrator yang sudah menaikkan atau menurunkan kewenangan
             * seseorang tidak boleh dikembalikan ke peran bawaan hanya karena
             * orang itu login lagi.
             */
            $user->forceFill([
                'name' => $identitas['name'],
                'email' => $identitas['email'] ?? $user->email,
            ])->save();

            return $user;
        }

        return $this->buatDariAd($identitas, $request);
    }

    /**
     * Membuat akun lokal untuk anggota grup yang belum pernah masuk.
     *
     * KATA SANDI DIBIARKAN NULL — itu penanda bahwa akun ini hidup dari AD,
     * dan sekaligus menutup jalur masuk kedua yang tidak pernah diminta
     * siapa pun. `Auth::attempt` tidak akan pernah meloloskan baris
     * berkata-sandi null, jadi akun ini hanya bisa masuk lewat direktori.
     *
     * @param  array{username: string, name: string, email: ?string, dn: string}  $identitas
     */
    private function buatDariAd(array $identitas, Request $request): User
    {
        $user = new User;
        $user->username = $identitas['username'];
        $user->name = $identitas['name'];
        $user->email = $identitas['email'];
        $user->password = null;
        $user->is_active = true;
        $user->must_change_password = false;
        $user->save();

        $kodePeran = config('ldap.default_role');

        if ($kodePeran !== null) {
            $peran = Role::query()->where('code', $kodePeran)->first();

            if ($peran !== null) {
                $user->roles()->syncWithoutDetaching([$peran->id]);
                $user->forgetPermissionCache();
            }
        }

        // Dicatat terpisah dari login-nya: akun yang lahir otomatis dari
        // direktori adalah peristiwa yang perlu bisa ditelusuri sendiri,
        // terutama karena perannya diberikan tanpa persetujuan siapa pun.
        $this->catatAudit('provision_ldap', $user, $request);

        return $user;
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
