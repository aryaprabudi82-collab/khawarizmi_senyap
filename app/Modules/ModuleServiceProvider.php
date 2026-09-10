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

        $this->loadCommands();
    }

    /**
     * Perintah artisan milik konteks ini.
     *
     * Didaftarkan dari dalam modulnya, sejalan dengan migrasi dan rute:
     * sebuah konteks harus bisa dibaca, dipindah, atau dicabut sebagai satu
     * unit, dan perintah yang terdaftar di luar akan tertinggal saat
     * modulnya dicabut lalu gagal memuat kelas yang sudah tidak ada.
     *
     * Hanya saat berjalan di konsol: memindai direktori pada setiap request
     * web adalah biaya yang tidak menghasilkan apa pun.
     */
    private function loadCommands(): void
    {
        if (! $this->app->runningInConsole()) {
            return;
        }

        $dir = $this->modulePath('Console/Commands');

        if (! is_dir($dir)) {
            return;
        }

        $kelas = [];

        foreach (glob($dir.'/*.php') ?: [] as $berkas) {
            $nama = 'App\\Modules\\'.$this->moduleName().'\\Console\\Commands\\'.basename($berkas, '.php');

            if (class_exists($nama)) {
                $kelas[] = $nama;
            }
        }

        if ($kelas !== []) {
            $this->commands($kelas);
        }
    }

    /** Nama folder modul, mis. "Platform". */
    private function moduleName(): string
    {
        $module = config("contexts.active.{$this->context()}.module");

        if (! is_string($module) || $module === '') {
            throw new \RuntimeException(
                "Konteks '{$this->context()}' belum punya 'module' di config/contexts.php."
            );
        }

        return $module;
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

        return rtrim(app_path("Modules/{$module}/".$relative), '/');
    }
}
