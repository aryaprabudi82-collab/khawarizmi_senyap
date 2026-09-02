<?php

namespace App\Modules\Integration\Services\Bpjs;

use App\Modules\Integration\Models\BpjsEligibilityCheck;

class EligibilityService
{
    public function __construct(private readonly BpjsClient $client) {}

    public function check(string $noKartu, string $tanggalPelayanan, ?int $checkedBy = null): BpjsEligibilityCheck
    {
        $result = $this->client->checkEligibility($noKartu, $tanggalPelayanan);

        return BpjsEligibilityCheck::query()->create([
            'no_kartu' => $noKartu,
            'service_date' => $tanggalPelayanan,
            'is_eligible' => $result['success'] ? ($result['data']['eligible'] ?? null) : null,
            'participant_name' => $result['data']['nama'] ?? null,
            'response_payload' => $result,
            'error_message' => $result['success'] ? null : $result['message'],
            'checked_by' => $checkedBy,
            'checked_at' => now(),
        ]);
    }
}
