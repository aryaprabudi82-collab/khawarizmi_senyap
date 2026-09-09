<?php

namespace App\Modules\Retail\Providers;

use App\Modules\ModuleServiceProvider;

class RetailServiceProvider extends ModuleServiceProvider
{
    protected function context(): string
    {
        return 'retail';
    }
}
