<?php

namespace Database\Seeders;

use App\Modules\Platform\Database\Seeders\PermissionCatalogSeeder;
use App\Modules\Platform\Database\Seeders\RoleSeeder;
use App\Modules\Platform\Database\Seeders\UserSeeder;
use App\Modules\Clinical\Database\Seeders\DiagnosisCodeSeeder;
use App\Modules\Finance\Database\Seeders\ChartOfAccountsSeeder;
use App\Modules\Inpatient\Database\Seeders\InpatientSeeder;
use App\Modules\Order\Database\Seeders\TestCatalogSeeder;
use App\Modules\Pharmacy\Database\Seeders\PharmacySeeder;
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
            PharmacySeeder::class,
            TestCatalogSeeder::class,
            ChartOfAccountsSeeder::class,
            InpatientSeeder::class,
            UserSeeder::class,
        ]);
    }
}
