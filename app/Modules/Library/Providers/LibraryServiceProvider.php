<?php

namespace App\Modules\Library\Providers;

use App\Modules\ModuleServiceProvider;

class LibraryServiceProvider extends ModuleServiceProvider
{
    protected function context(): string
    {
        return 'library';
    }
}
