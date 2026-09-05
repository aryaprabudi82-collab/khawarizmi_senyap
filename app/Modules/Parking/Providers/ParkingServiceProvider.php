<?php

namespace App\Modules\Parking\Providers;

use App\Modules\ModuleServiceProvider;

class ParkingServiceProvider extends ModuleServiceProvider
{
    protected function context(): string
    {
        return 'parking';
    }
}
