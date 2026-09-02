<?php

namespace App\Modules\Platform\Providers;

use App\Modules\ModuleServiceProvider;
use App\Modules\Platform\Services\PermissionRegistry;

class PlatformServiceProvider extends ModuleServiceProvider
{
    protected function context(): string
    {
        return 'platform';
    }

    public function register(): void
    {
        $this->app->singleton(PermissionRegistry::class);
    }

    public function boot(): void
    {
        parent::boot();

        // Mendaftarkan seluruh permission sebagai Gate, sehingga
        // $user->can('registrasi') bekerja tanpa Gate::define satu per satu.
        $this->app->make(PermissionRegistry::class)->registerGates();
    }
}
