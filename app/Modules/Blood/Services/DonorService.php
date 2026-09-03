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
}
