<?php

namespace App\Modules\Inpatient\Providers;

use App\Modules\ModuleServiceProvider;

class InpatientServiceProvider extends ModuleServiceProvider
{
    protected function context(): string
    {
        return 'inpatient';
    }
}
