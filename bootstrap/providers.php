<?php

use App\Modules\Asset\Providers\AssetServiceProvider;
use App\Modules\Billing\Providers\BillingServiceProvider;
use App\Modules\Blood\Providers\BloodServiceProvider;
use App\Modules\Catalog\Providers\CatalogServiceProvider;
use App\Modules\Clinical\Providers\ClinicalServiceProvider;
use App\Modules\Correspondence\Providers\CorrespondenceServiceProvider;
use App\Modules\Encounter\Providers\EncounterServiceProvider;
use App\Modules\Envlab\Providers\EnvlabServiceProvider;
use App\Modules\Finance\Providers\FinanceServiceProvider;
use App\Modules\Hr\Providers\HrServiceProvider;
use App\Modules\Identity\Providers\IdentityServiceProvider;
use App\Modules\Inpatient\Providers\InpatientServiceProvider;
use App\Modules\Integration\Providers\IntegrationServiceProvider;
use App\Modules\Inventory\Providers\InventoryServiceProvider;
use App\Modules\Keuangan\MasterData\Providers\KeuanganMasterDataServiceProvider;
use App\Modules\Kitchen\Providers\KitchenServiceProvider;
use App\Modules\Library\Providers\LibraryServiceProvider;
use App\Modules\Order\Providers\OrderServiceProvider;
use App\Modules\Organization\Providers\OrganizationServiceProvider;
use App\Modules\Parking\Providers\ParkingServiceProvider;
use App\Modules\Pharmacy\Providers\PharmacyServiceProvider;
use App\Modules\Philanthropy\Providers\PhilanthropyServiceProvider;
use App\Modules\Platform\Providers\PlatformServiceProvider;
use App\Modules\Quality\Providers\QualityServiceProvider;
use App\Modules\Reporting\Providers\ReportingServiceProvider;
use App\Modules\Retail\Providers\RetailServiceProvider;
use App\Providers\AppServiceProvider;

return [
    AppServiceProvider::class,

    // Satu provider per bounded context. Urutan mengikuti ketergantungannya:
    // encounter memerlukan identity, organization, dan catalog.
    PlatformServiceProvider::class,
    CatalogServiceProvider::class,
    OrganizationServiceProvider::class,
    IdentityServiceProvider::class,
    EncounterServiceProvider::class,
    ClinicalServiceProvider::class,
    PharmacyServiceProvider::class,
    OrderServiceProvider::class,
    BillingServiceProvider::class,
    FinanceServiceProvider::class,
    IntegrationServiceProvider::class,
    ReportingServiceProvider::class,
    HrServiceProvider::class,
    QualityServiceProvider::class,
    InventoryServiceProvider::class,
    KeuanganMasterDataServiceProvider::class,
    BloodServiceProvider::class,
    CorrespondenceServiceProvider::class,
    AssetServiceProvider::class,
    InpatientServiceProvider::class,
    EnvlabServiceProvider::class,
    LibraryServiceProvider::class,
    KitchenServiceProvider::class,
    ParkingServiceProvider::class,
    RetailServiceProvider::class,
    PhilanthropyServiceProvider::class,
];
