<?php

namespace App\Modules\Blood\Providers;

use App\Modules\ModuleServiceProvider;

class BloodServiceProvider extends ModuleServiceProvider
{
    protected function context(): string
    {
        return 'blood';
    }
}
