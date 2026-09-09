<?php

namespace App\Modules\Philanthropy\Providers;

use App\Modules\ModuleServiceProvider;

class PhilanthropyServiceProvider extends ModuleServiceProvider
{
    protected function context(): string
    {
        return 'philanthropy';
    }
}
