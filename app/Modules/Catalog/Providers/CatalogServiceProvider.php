<?php

namespace App\Modules\Catalog\Providers;

use App\Modules\ModuleServiceProvider;

class CatalogServiceProvider extends ModuleServiceProvider
{
    protected function context(): string
    {
        return "catalog";
    }
}
