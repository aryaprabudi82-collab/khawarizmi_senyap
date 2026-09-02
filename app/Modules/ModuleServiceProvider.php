<?php

namespace App\Modules;

use Illuminate\Support\ServiceProvider;

/**
 * Induk seluruh service provider modul.
 *
 * Tiap bounded context memuat migrasi, seeder, rute, dan view-nya sendiri dari
 * dalam foldernya. Tidak ada berkas modul yang berserakan di luar app/Modules,
 * supaya sebuah konteks bisa dibaca, dipindah, atau dicabut sebagai satu unit.
 */
abstract class ModuleServiceProvider extends ServiceProvider
{
    /** Nama konteks sebagaimana terdaftar di config/contexts.php */
    abstract protected function context(): string;

    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom($this->modulePath('Database/Migrations'));

        if (is_dir($views = $this->modulePath('Resources/views'))) {
            $this->loadViewsFrom($views, $this->context());
        }

        if (is_file($routes = $this->modulePath('Routes/web.php'))) {
            $this->loadRoutesFrom($routes);
        }
    }

    /** Schema PostgreSQL milik konteks ini. */
    protected function schema(): string
    {
        $schema = config("contexts.active.{$this->context()}.schema");

        if (! is_string($schema) || $schema === '') {
            throw new \RuntimeException(
                "Konteks '{$this->context()}' belum terdaftar di config/contexts.php."
            );
        }

        return $schema;
    }

    protected function modulePath(string $relative = ''): string
    {
        $module = str($this->context())->studly()->value();

        return rtrim(app_path("Modules/{$module}/" . $relative), '/');
    }
}
