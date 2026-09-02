<?php

namespace App\Modules\Order\Providers;

use App\Modules\ModuleServiceProvider;

class OrderServiceProvider extends ModuleServiceProvider
{
    protected function context(): string
    {
        return "order";
    }
}
