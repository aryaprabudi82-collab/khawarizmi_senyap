<?php

namespace App\Modules\Platform\Console\Commands;

use App\Modules\Platform\Models\Role;
use App\Modules\Platform\Models\User;
use App\Modules\Platform\Services\LdapAuthenticator;
use Illuminate\Console\Command;
use Throwable;

/**
 * Membuat akun pengguna dari Active Directory dan menetapkan perannya.
 *
 * MENGAPA PERINTAH, BUKAN SEEDER. `UserSeeder` sengaja dilewati di produksi
 * dan berisi akun contoh berkata sandi "password" — akun orang sungguhan
 * tidak boleh tinggal di sana. Perintah ini bisa dijalankan di produksi,
 * berkali-kali, tanpa menggandakan apa pun.
 *
 * AKUN DIVERIFIKASI KE DIREKTORI LEBIH DULU. Membuat akun untuk nama
 * pengguna yang tidak ada di AD — atau yang ada tapi bukan anggota grup —
 * menghasilkan akun yang tidak akan pernah bisa dipakai masuk, dan yang
 * menemukannya adalah orangnya sendiri saat gagal login berkali-kali.
 *
 * KATA SANDI DIBIARKAN NULL. Akun ini masuk lewat AD; kata sandi lokal yang
 * ikut dibuat hanya menambah satu rahasia lagi yang harus dijaga tanpa ada
 * yang memakainya.
 */
class BuatPenggunaAdmin extends Command
{
    protected $signature = 'platform:buat-admin
        {username : Nama pengguna Active Directory, mis. mohammad.hud}
        {--peran=admin-sistem : Kode peran yang diberikan}
        {--tanpa-ldap : Buat akun tanpa memverifikasi ke direktori (hanya untuk keadaan darurat)}';

    protected $description = 'Membuat akun pengguna dari Active Directory dan menetapkan perannya';

    public function handle(LdapAuthenticator $ldap): int
    {
        $username = mb_strtolower(trim((string) $this->argument('username')));
        $kodePeran = (string) $this->option('peran');

        if ($username === '') {
            $this->error('Nama pengguna wajib diisi.');

            return self::FAILURE;
        }

        $peran = Role::query()->where('code', $kodePeran)->first();

        if ($peran === null) {
            $this->error("Peran '{$kodePeran}' tidak ada.");
            $this->line('Peran yang tersedia: '.Role::query()->orderBy('code')->pluck('code')->implode(', '));

            return self::FAILURE;
        }

        $dariAd = null;

        if (! $this->option('tanpa-ldap')) {
            try {
                $dariAd = $ldap->cariPengguna($username);
            } catch (Throwable $e) {
                $this->error('Tidak bisa menghubungi Active Directory: '.$e->getMessage());
                $this->line('Pakai --tanpa-ldap bila akun memang harus dibuat sekarang juga.');

                return self::FAILURE;
            }

            if ($dariAd === null) {
                $this->error("'{$username}' tidak ditemukan di direktori, atau bukan anggota grup yang diizinkan.");
                $this->line('Akun yang dibuat sekarang tidak akan bisa dipakai masuk.');

                return self::FAILURE;
            }

            $this->info("Ditemukan di direktori: {$dariAd['name']} <".($dariAd['email'] ?? 'tanpa surel').'>');
        }

        $user = User::query()->where('username', $username)->first();
        $baru = $user === null;

        if ($baru) {
            $user = new User;
            $user->username = $username;
        }

        $user->name = $dariAd['name'] ?? $user->name ?? $username;
        $user->email = $dariAd['email'] ?? $user->email;
        $user->is_active = true;

        /*
         * Kata sandi TIDAK disentuh untuk akun yang sudah ada. Bila seorang
         * administrator sudah menyiapkan kata sandi darurat, menghapusnya di
         * sini berarti mencabut satu-satunya jalan masuk saat AD mati.
         */
        if ($baru) {
            $user->password = null;
            $user->must_change_password = false;
        }

        $user->save();

        /*
         * Peran DITAMBAHKAN, bukan disamakan. `sync()` akan mencabut peran
         * lain yang mungkin sudah diberikan administrator, dan perintah yang
         * dijalankan untuk menambah kewenangan tidak boleh diam-diam
         * mencabut kewenangan lain.
         */
        $user->roles()->syncWithoutDetaching([$peran->id]);
        $user->forgetPermissionCache();

        $this->info(($baru ? 'Akun dibuat' : 'Akun diperbarui').": {$user->username} (#{$user->id})");
        $this->line('Peran sekarang: '.$user->roles()->pluck('code')->implode(', '));

        if ($user->password === null) {
            $this->line('Kata sandi lokal: tidak ada — akun ini masuk lewat Active Directory.');
        }

        if ($peran->code === User::SUPER_ADMIN) {
            $this->warn(
                'Peran super-admin melewati SELURUH pemeriksaan hak lewat Gate::before, '
                .'termasuk pemeriksaan is_active. Menonaktifkan akun ini hanya menutup '
                .'pintu login, bukan sesi yang sedang berjalan.'
            );
        }

        return self::SUCCESS;
    }
}
