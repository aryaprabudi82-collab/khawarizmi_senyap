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

    /** Tag container tempat seluruh pemeriksaan kesiapan konteks dikumpulkan. */
    public const READINESS_TAG = 'kesiapan-konteks';

    /**
     * FINAL, DAN ITU HASIL SEBUAH CACAT NYATA.
     *
     * IntegrationServiceProvider menimpa register() tanpa memanggil
     * parent::register(), sehingga IntegrationReadiness tidak pernah
     * terdaftar — dan `siap:periksa` melaporkan sistem siap tanpa pernah
     * menyebut bahwa enam belas mesin integrasi tidak punya layar. Tidak
     * ada galat, tidak ada uji merah: butirnya cuma tidak muncul, dan
     * laporan yang KEHILANGAN butir terbaca persis seperti laporan yang
     * butirnya beres.
     *
     * Menambahkan uji "setiap provider wajib memanggil parent::register()"
     * akan menangkap kejadian berikutnya. Membuat method ini final
     * MENIADAKAN kejadian berikutnya: modul yang perlu mendaftarkan
     * ikatannya sendiri menimpa registerBindings(), dan kesiapannya tidak
     * mungkin ikut hilang.
     */
    final public function register(): void
    {
        $this->registerReadinessCheck();
        $this->registerBindings();
    }

    /**
     * Ikatan container milik modul ini.
     *
     * Ditimpa modul yang membutuhkannya; yang tidak, tidak perlu menulis
     * apa pun.
     */
    protected function registerBindings(): void
    {
        //
    }

    /**
     * Mendaftarkan pemeriksaan kesiapan milik konteks ini, bila ada.
     *
     * Konvensi, bukan konfigurasi: kelas bernama
     * `App\Modules\{Modul}\Services\{Modul}Readiness` yang mengimplementasikan
     * ReadinessCheck otomatis ikut terkumpul. Daftar terpusat di satu berkas
     * akan benar hari ini dan diam-diam tertinggal saat konteks berikutnya
     * menambah syarat kesiapannya sendiri.
     */
    private function registerReadinessCheck(): void
    {
        $kelas = 'App\\Modules\\'.$this->moduleName().'\\Services\\'.$this->moduleName().'Readiness';

        if (! class_exists($kelas) || ! is_a($kelas, ReadinessCheck::class, true)) {
            return;
        }

        $this->app->singleton($kelas);
        $this->app->tag([$kelas], self::READINESS_TAG);
    }

    public function boot(): void
    {
        /*
         * DUA TATA LETAK MIGRASI, dan keduanya sah.
         *
         * Dua puluh lima konteks yang sudah ada memakai `Database/Migrations`.
         * Sub-konteks domain keuangan memakai struktur DDD yang diminta
         * BAGIAN 5 instruksi — `Infrastructure/Migrations`.
         *
         * Keduanya dimuat, bukan yang lama dipindahkan: memindahkan 500
         * berkas migrasi demi keseragaman tata letak adalah perubahan
         * besar yang tidak menambah satu pun kebenaran, dan setiap
         * migrasi yang salah pindah berarti skema yang tidak terbentuk.
         */
        foreach (['Database/Migrations', 'Infrastructure/Migrations'] as $jalur) {
            if (is_dir($dir = $this->modulePath($jalur))) {
                $this->loadMigrationsFrom($dir);
            }
        }

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
        return rtrim(app_path('Modules/'.$this->moduleDirectory().'/'.$relative), '/');
    }

    /**
     * Direktori modul, relatif terhadap app/Modules.
     *
     * Bawaannya diturunkan dari nama konteks — `pharmacy` jadi `Pharmacy`.
     * Sub-konteks domain keuangan MENIMPANYA karena letaknya bersarang:
     * konteks `keuangan_master` tinggal di `Keuangan/MasterData`, bukan
     * `KeuanganMaster`.
     *
     * Diturunkan begini, bukan ditulis di config/contexts.php, supaya
     * manifes konteks tetap menjawab satu pertanyaan saja — batas dan
     * kontrak antar konteks — dan tidak berubah jadi peta direktori.
     */
    protected function moduleDirectory(): string
    {
        return str($this->context())->studly()->value();
    }
}
