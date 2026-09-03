<?php

namespace App\Modules\Inventory\Providers;

use App\Modules\ModuleServiceProvider;

class InventoryServiceProvider extends ModuleServiceProvider
{
    protected function context(): string
    {
        return 'inventory';
    }
}
