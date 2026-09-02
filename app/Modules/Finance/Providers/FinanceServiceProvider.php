<?php

namespace App\Modules\Finance\Providers;

use App\Modules\ModuleServiceProvider;

class FinanceServiceProvider extends ModuleServiceProvider
{
    protected function context(): string
    {
        return "finance";
    }
}
