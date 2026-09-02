<?php

return [
    App\Providers\AppServiceProvider::class,

    // Satu provider per bounded context. Urutan mengikuti ketergantungannya:
    // encounter memerlukan identity, organization, dan catalog.
    App\Modules\Platform\Providers\PlatformServiceProvider::class,
    App\Modules\Catalog\Providers\CatalogServiceProvider::class,
    App\Modules\Organization\Providers\OrganizationServiceProvider::class,
    App\Modules\Identity\Providers\IdentityServiceProvider::class,
    App\Modules\Encounter\Providers\EncounterServiceProvider::class,
    App\Modules\Clinical\Providers\ClinicalServiceProvider::class,
    App\Modules\Pharmacy\Providers\PharmacyServiceProvider::class,
    App\Modules\Order\Providers\OrderServiceProvider::class,
    App\Modules\Billing\Providers\BillingServiceProvider::class,
    App\Modules\Finance\Providers\FinanceServiceProvider::class,
];
