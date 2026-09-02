<?php

namespace App\Modules\Hr\Providers;

use App\Modules\ModuleServiceProvider;

class HrServiceProvider extends ModuleServiceProvider
{
    protected function context(): string
    {
        return 'hr';
    }
}
