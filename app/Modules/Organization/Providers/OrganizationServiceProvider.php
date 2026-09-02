<?php

namespace App\Modules\Organization\Providers;

use App\Modules\ModuleServiceProvider;

class OrganizationServiceProvider extends ModuleServiceProvider
{
    protected function context(): string
    {
        return "organization";
    }
}
