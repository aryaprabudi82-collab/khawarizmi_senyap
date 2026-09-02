<?php

namespace App\Modules\Billing\Providers;

use App\Modules\ModuleServiceProvider;

class BillingServiceProvider extends ModuleServiceProvider
{
    protected function context(): string
    {
        return "billing";
    }
}
