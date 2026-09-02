<?php

namespace App\Modules\Reporting\Providers;

use App\Modules\ModuleServiceProvider;
use App\Modules\Reporting\Console\Commands\SyncReportingCommand;

class ReportingServiceProvider extends ModuleServiceProvider
{
    protected function context(): string
    {
        return 'reporting';
    }

    public function boot(): void
    {
        parent::boot();

        if ($this->app->runningInConsole()) {
            $this->commands([SyncReportingCommand::class]);
        }
    }
}
