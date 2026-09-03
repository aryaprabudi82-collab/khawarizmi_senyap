<?php

namespace App\Modules\Envlab\Services;

use App\Modules\Envlab\Models\Customer;
use App\Modules\Envlab\Models\QualityStandard;
use App\Modules\Envlab\Models\SampleType;
use App\Modules\Envlab\Models\TestParameter;

/** Pintu masuk data master konteks envlab. */
class EnvlabMasterDataService
{
    public function createCustomer(array $data): Customer
    {
        return Customer::query()->create($data);
    }

    public function updateCustomer(Customer $customer, array $data): Customer
    {
        $customer->update($data);

        return $customer->refresh();
    }

    public function createSampleType(array $data): SampleType
    {
        return SampleType::query()->create($data);
    }

    public function updateSampleType(SampleType $sampleType, array $data): SampleType
    {
        $sampleType->update($data);

        return $sampleType->refresh();
    }

    public function createParameter(array $data): TestParameter
    {
        return TestParameter::query()->create($data);
    }

    public function updateParameter(TestParameter $parameter, array $data): TestParameter
    {
        $parameter->update($data);

        return $parameter->refresh();
    }

    /** @throws EnvlabException */
    public function setQualityStandard(int $sampleTypeId, int $parameterId, array $data): QualityStandard
    {
        if (($data['min_value'] ?? null) === null && ($data['max_value'] ?? null) === null && ($data['qualitative_standard'] ?? null) === null) {
            throw new EnvlabException('Isi rentang nilai (minimal/maksimal) atau standar kualitatif.');
        }

        return QualityStandard::query()->updateOrCreate(
            ['sample_type_id' => $sampleTypeId, 'parameter_id' => $parameterId],
            $data + ['is_active' => true]
        );
    }
}
