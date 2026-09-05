<?php

namespace App\Modules\Blood\Services;

use App\Modules\Blood\Models\Donor;

class DonorService
{
    public function __construct(private readonly NumberAllocator $numbers) {}

    public function register(array $data): Donor
    {
        return Donor::query()->create($data + [
            'donor_number' => $this->numbers->allocate('DNR'),
            'is_active' => true,
        ]);
    }

    public function update(Donor $donor, array $data): Donor
    {
        $donor->update($data);

        return $donor->refresh();
    }

    /** utd_cekal_darah — cekal pendonor. blockedUntil null berarti cekal permanen. */
    public function block(Donor $donor, string $reason, ?string $blockedUntil = null): Donor
    {
        $donor->update([
            'block_reason' => $reason,
            'blocked_until' => $blockedUntil,
        ]);

        return $donor->refresh();
    }

    /** utd_cekal_darah — cabut cekal pendonor. */
    public function unblock(Donor $donor): Donor
    {
        $donor->update([
            'block_reason' => null,
            'blocked_until' => null,
        ]);

        return $donor->refresh();
    }
}
