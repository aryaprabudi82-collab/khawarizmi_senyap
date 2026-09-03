<?php

namespace App\Modules\Platform\Database\Seeders;

use App\Modules\Platform\Models\Role;
use App\Modules\Platform\Models\User;
use Illuminate\Database\Seeder;

/**
 * Akun awal untuk lingkungan pengembangan.
 *
 * Tidak dijalankan di produksi: akun produksi dibuat lewat SSO/AD atau oleh
 * administrator, bukan dari seeder dengan kata sandi yang tertulis di source.
 */
class UserSeeder extends Seeder
{
    public function run(): void
    {
        if (app()->environment('production')) {
            $this->command?->warn('UserSeeder dilewati di lingkungan produksi.');

            return;
        }

        $akun = [
            ['username' => 'admin', 'name' => 'Administrator Sistem', 'role' => 'super-admin', 'mfa' => true],
            ['username' => 'loket1', 'name' => 'Rina Oktaviani', 'role' => 'petugas-daftar', 'mfa' => false],
            ['username' => 'loket2', 'name' => 'Dedi Kurniawan', 'role' => 'petugas-daftar', 'mfa' => false],
            ['username' => 'master', 'name' => 'Sri Handayani', 'role' => 'admin-master', 'mfa' => false],
            ['username' => 'dokter1', 'name' => 'Andi Wijaya', 'role' => 'dokter', 'mfa' => false],
            ['username' => 'apoteker1', 'name' => 'Yuli Astuti', 'role' => 'apoteker', 'mfa' => false],
            ['username' => 'kasir1', 'name' => 'Dian Permatasari', 'role' => 'kasir', 'mfa' => false],
            ['username' => 'lab1', 'name' => 'Farid Setiadi', 'role' => 'petugas-lab', 'mfa' => false],
            ['username' => 'radiologi1', 'name' => 'Nadia Kartika', 'role' => 'petugas-radiologi', 'mfa' => false],
            ['username' => 'keuangan1', 'name' => 'Bambang Wijaya', 'role' => 'petugas-keuangan', 'mfa' => false],
            ['username' => 'integrasi1', 'name' => 'Fajar Nugroho', 'role' => 'petugas-integrasi', 'mfa' => false],
            ['username' => 'manajemen1', 'name' => 'Siti Rahayu', 'role' => 'manajemen', 'mfa' => false],
            ['username' => 'hr1', 'name' => 'Wulan Setiawati', 'role' => 'admin-hr', 'mfa' => false],
            ['username' => 'mutu1', 'name' => 'Anisa Puspita', 'role' => 'admin-mutu', 'mfa' => false],
            ['username' => 'logistik1', 'name' => 'Bayu Kurniawan', 'role' => 'petugas-logistik', 'mfa' => false],
            ['username' => 'utd1', 'name' => 'Rizky Ramadhan', 'role' => 'petugas-utd', 'mfa' => false],
            ['username' => 'tu1', 'name' => 'Dewi Lestari', 'role' => 'petugas-tu', 'mfa' => false],
            ['username' => 'aset1', 'name' => 'Hendra Gunawan', 'role' => 'petugas-aset', 'mfa' => false],
        ];

        foreach ($akun as $data) {
            $user = User::query()->updateOrCreate(
                ['username' => $data['username']],
                [
                    'name' => $data['name'],
                    'password' => 'password',
                    'is_active' => true,
                    'mfa_required' => $data['mfa'],
                ]
            );

            $role = Role::query()->where('code', $data['role'])->first();

            if ($role !== null) {
                $user->roles()->sync([$role->id]);
            }
        }

        $this->command?->info('Akun pengembangan: ' . count($akun) . ' akun disiapkan, kata sandi "password".');
        $this->command?->warn('Ganti seluruh kata sandi ini sebelum sistem dipakai melayani pasien.');
    }
}
