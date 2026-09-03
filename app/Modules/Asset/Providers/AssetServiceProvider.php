<?php

namespace App\Modules\Asset\Providers;

use App\Modules\ModuleServiceProvider;

class AssetServiceProvider extends ModuleServiceProvider
{
    protected function context(): string
    {
        return 'asset';
    }
}
