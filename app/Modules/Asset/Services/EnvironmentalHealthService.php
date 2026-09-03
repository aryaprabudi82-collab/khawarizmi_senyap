<?php

namespace App\Modules\Asset\Services;

use App\Modules\Asset\Models\EnvironmentalMeasurement;
use App\Modules\Asset\Models\PestControlVisit;
use App\Modules\Platform\Models\User;

/**
 * Kesling murni pencatatan berkala, bukan alur kerja — tidak ada status
 * yang berpindah di sini, jadi tidak ada AssetException seperti CSSD/
 * pemeliharaan. Sekali dicatat, jadi bagian riwayat kepatuhan; tidak ada
 * method update/delete secara sengaja.
 */
class EnvironmentalHealthService
{
    public function recordMeasurement(array $data, User $recordedBy): EnvironmentalMeasurement
    {
        return EnvironmentalMeasurement::query()->create($data + ['recorded_by' => $recordedBy->id]);
    }

    public function recordPestControlVisit(array $data, User $recordedBy): PestControlVisit
    {
        return PestControlVisit::query()->create($data + ['recorded_by' => $recordedBy->id]);
    }
}
