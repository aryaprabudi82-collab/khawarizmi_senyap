<?php

namespace App\Modules\Correspondence\Providers;

use App\Modules\ModuleServiceProvider;

class CorrespondenceServiceProvider extends ModuleServiceProvider
{
    protected function context(): string
    {
        return 'correspondence';
    }
}
