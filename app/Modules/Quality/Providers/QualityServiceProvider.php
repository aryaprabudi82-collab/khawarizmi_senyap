<?php

namespace App\Modules\Quality\Providers;

use App\Modules\ModuleServiceProvider;

class QualityServiceProvider extends ModuleServiceProvider
{
    protected function context(): string
    {
        return 'quality';
    }
}
