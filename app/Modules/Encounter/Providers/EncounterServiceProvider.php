<?php

namespace App\Modules\Encounter\Providers;

use App\Modules\ModuleServiceProvider;

class EncounterServiceProvider extends ModuleServiceProvider
{
    protected function context(): string
    {
        return "encounter";
    }
}
