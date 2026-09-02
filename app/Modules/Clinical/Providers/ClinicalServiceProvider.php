<?php

namespace App\Modules\Clinical\Providers;

use App\Modules\ModuleServiceProvider;

class ClinicalServiceProvider extends ModuleServiceProvider
{
    protected function context(): string
    {
        return "clinical";
    }
}
