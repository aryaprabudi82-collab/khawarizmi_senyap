<?php

return [
    App\Providers\AppServiceProvider::class,

    // Satu provider per bounded context. Urutannya mengikuti ketergantungan.
    App\Modules\Platform\Providers\PlatformServiceProvider::class,
];
