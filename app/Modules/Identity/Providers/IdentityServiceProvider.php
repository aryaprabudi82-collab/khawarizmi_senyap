<?php

namespace App\Modules\Identity\Providers;

use App\Modules\ModuleServiceProvider;

class IdentityServiceProvider extends ModuleServiceProvider
{
    protected function context(): string
    {
        return "identity";
    }
}
