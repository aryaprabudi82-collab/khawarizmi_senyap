<?php

namespace App\Modules\Quality\Services;

use App\Modules\Quality\Models\PpiAudit;
use App\Modules\Platform\Models\User;

/** Audit PPI murni pencatatan berkala, tidak ada status yang berpindah — sekali dicatat, jadi riwayat kepatuhan. */
class PpiAuditService
{
    public function record(array $data, User $auditor): PpiAudit
    {
        return PpiAudit::query()->create($data + ['auditor_id' => $auditor->id]);
    }
}
