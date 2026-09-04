<?php

namespace App\Modules\Kitchen\Providers;

use App\Modules\ModuleServiceProvider;

class KitchenServiceProvider extends ModuleServiceProvider
{
    protected function context(): string
    {
        return 'kitchen';
    }
}
