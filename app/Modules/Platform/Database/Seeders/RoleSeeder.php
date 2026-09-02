<?php

namespace App\Modules\Platform\Database\Seeders;

use App\Modules\Platform\Models\Permission;
use App\Modules\Platform\Models\Role;
use Illuminate\Database\Seeder;

/**
 * Peran bawaan sistem.
 *
 * Sengaja hanya peran yang benar-benar dipakai milestone pertama. Peran lain
 * dibuat saat domainnya digarap — menebak matriks peran untuk 21 domain
 * sekarang hanya menghasilkan daftar yang tidak pernah dipakai dan tidak pernah
 * ditinjau ulang.
 *
 * super-admin sengaja tidak diberi baris di role_permission: pengecualiannya
 * ditangani PermissionRegistry lewat Gate::before, sehingga kapabilitas baru
 * otomatis ikut tanpa perlu menyinkronkan ulang.
 */
class RoleSeeder extends Seeder
{
    public function run(): void
    {
        $path = database_path('data/roles.json');

        if (! is_file($path)) {
            $this->command?->error("Definisi peran tidak ditemukan: {$path}");

            return;
        }

        /** @var list<array<string, mixed>> $definitions */
        $definitions = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);

        foreach ($definitions as $definition) {
            $role = Role::query()->updateOrCreate(
                ['code' => $definition['code']],
                [
                    'name' => $definition['name'],
                    'description' => $definition['description'],
                    'is_system' => true,
                ]
            );

            if (! empty($definition['all'])) {
                $this->command?->line("  {$role->code}: seluruh kapabilitas (lewat Gate::before)");

                continue;
            }

            $codes = Permission::query()
                ->whereIn('context', $definition['contexts'] ?? [])
                ->pluck('code')
                ->all();

            $role->syncPermissionCodes($codes);

            $this->command?->line("  {$role->code}: " . count($codes) . ' kapabilitas');
        }

        $this->command?->info('Peran bawaan: ' . count($definitions) . ' peran disiapkan.');
    }
}
