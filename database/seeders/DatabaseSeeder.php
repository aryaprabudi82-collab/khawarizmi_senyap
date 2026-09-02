<?php

namespace Database\Seeders;

use App\Modules\Platform\Database\Seeders\PermissionCatalogSeeder;
use App\Modules\Platform\Database\Seeders\RoleSeeder;
use App\Modules\Platform\Database\Seeders\UserSeeder;
use App\Modules\Clinical\Database\Seeders\DiagnosisCodeSeeder;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        // Urutan mengikuti ketergantungan: peran memerlukan katalog permission.
        $this->call([
            PermissionCatalogSeeder::class,
            RoleSeeder::class,
            ReferenceDataSeeder::class,
            DiagnosisCodeSeeder::class,
            UserSeeder::class,
        ]);
    }
}
