<?php

namespace App\Modules\Pharmacy\Providers;

use App\Modules\ModuleServiceProvider;

class PharmacyServiceProvider extends ModuleServiceProvider
{
    protected function context(): string
    {
        return "pharmacy";
    }
}
