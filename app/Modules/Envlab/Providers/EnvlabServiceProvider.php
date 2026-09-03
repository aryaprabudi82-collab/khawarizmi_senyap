<?php

namespace App\Modules\Envlab\Providers;

use App\Modules\ModuleServiceProvider;

class EnvlabServiceProvider extends ModuleServiceProvider
{
    protected function context(): string
    {
        return "envlab";
    }
}
