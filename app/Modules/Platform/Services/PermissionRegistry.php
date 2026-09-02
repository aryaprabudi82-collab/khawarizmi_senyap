<?php

namespace App\Modules\Platform\Services;

use App\Modules\Platform\Models\Permission;
use App\Modules\Platform\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Menjembatani katalog permission di basis data dengan Gate milik Laravel.
 *
 * Tidak ada Gate::define satu per satu untuk 1.183 kapabilitas. Sebagai gantinya
 * satu Gate::before menangani semuanya, sehingga tidak ada query saat boot dan
 * $user->can('registrasi') tetap bekerja seperti biasa.
 */
class PermissionRegistry
{
    private const CACHE_KEY = 'platform.permission-codes';
    private const CACHE_TTL = 3600;

    public function registerGates(): void
    {
        Gate::before(function ($user, string $ability) {
            if (! $user instanceof User) {
                return null;
            }

            // Super admin melewati seluruh pemeriksaan permission. Pembatasan
            // berbasis atribut (mis. dokter hanya melihat pasiennya) tetap
            // ditangani policy masing-masing konteks, bukan di sini.
            if ($user->isSuperAdmin()) {
                return true;
            }

            // Ability yang bukan permission dibiarkan jatuh ke policy biasa.
            if (! $this->isPermission($ability)) {
                return null;
            }

            return $user->is_active && $user->hasPermission($ability);
        });
    }

    /** Seluruh kode permission yang terdaftar. */
    public function codes(): array
    {
        return Cache::remember(self::CACHE_KEY, self::CACHE_TTL, function (): array {
            try {
                if (! Schema::hasTable((new Permission)->getTable())) {
                    return [];
                }

                return Permission::query()->pluck('code')->all();
            } catch (Throwable) {
                // Basis data belum siap (migrasi awal, build CI tanpa DB).
                // Ketiadaan katalog tidak boleh menjatuhkan aplikasi.
                return [];
            }
        });
    }

    public function isPermission(string $code): bool
    {
        return in_array($code, $this->codes(), true);
    }

    public function flush(): void
    {
        Cache::forget(self::CACHE_KEY);
    }
}
