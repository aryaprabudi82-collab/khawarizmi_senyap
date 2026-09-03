<?php

namespace Tests\Feature\Envlab;

use App\Modules\Envlab\Models\Customer;
use App\Modules\Envlab\Models\QualityStandard;
use App\Modules\Envlab\Services\EnvlabException;
use App\Modules\Envlab\Services\EnvlabMasterDataService;
use App\Modules\Platform\Database\Seeders\PermissionCatalogSeeder;
use App\Modules\Platform\Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class EnvlabMasterDataTest extends TestCase
{
    use RefreshDatabase;

    private EnvlabMasterDataService $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([PermissionCatalogSeeder::class, RoleSeeder::class]);

        $this->admin = app(EnvlabMasterDataService::class);
    }

    #[Test]
    public function pelanggan_tersimpan(): void
    {
        $pelanggan = $this->admin->createCustomer([
            'code' => 'PLG-001', 'name' => 'PT Air Bersih Sejahtera', 'kind' => Customer::KIND_EKSTERNAL,
        ]);

        $this->assertSame('PT Air Bersih Sejahtera', $pelanggan->name);
        $this->assertSame(Customer::KIND_EKSTERNAL, $pelanggan->kind);
    }

    #[Test]
    public function jenis_sampel_tersimpan(): void
    {
        $sampel = $this->admin->createSampleType([
            'code' => 'SPL-AL', 'name' => 'Air Limbah Domestik', 'category' => 'air-limbah',
            'regulatory_reference' => 'PermenLHK P.68/2016',
        ]);

        $this->assertSame('air-limbah', $sampel->category);
    }

    #[Test]
    public function parameter_tersimpan(): void
    {
        $parameter = $this->admin->createParameter([
            'code' => 'PAR-BOD', 'name' => 'BOD', 'unit' => 'mg/L', 'test_method' => 'SNI 6989.72:2009',
        ]);

        $this->assertSame('mg/L', $parameter->unit);
    }

    #[Test]
    public function baku_mutu_kuantitatif_tersimpan_dengan_rentang(): void
    {
        $sampel = $this->admin->createSampleType(['code' => 'SPL-AL', 'name' => 'Air Limbah', 'category' => 'air-limbah']);
        $parameter = $this->admin->createParameter(['code' => 'PAR-BOD', 'name' => 'BOD', 'unit' => 'mg/L']);

        $bakuMutu = $this->admin->setQualityStandard($sampel->id, $parameter->id, [
            'max_value' => 30, 'regulatory_reference' => 'PermenLHK P.68/2016',
        ]);

        $this->assertSame('30.0000', $bakuMutu->max_value);
        $this->assertSame('maks. 30.0000', $bakuMutu->displayRange());
    }

    #[Test]
    public function baku_mutu_kualitatif_tersimpan_dengan_standar_teks(): void
    {
        $sampel = $this->admin->createSampleType(['code' => 'SPL-MM', 'name' => 'Makanan Siap Saji', 'category' => 'makanan-minuman']);
        $parameter = $this->admin->createParameter(['code' => 'PAR-SALM', 'name' => 'Salmonella']);

        $bakuMutu = $this->admin->setQualityStandard($sampel->id, $parameter->id, [
            'qualitative_standard' => 'Negatif',
        ]);

        $this->assertSame('Negatif', $bakuMutu->displayRange());
    }

    #[Test]
    public function baku_mutu_tanpa_nilai_apapun_ditolak(): void
    {
        $sampel = $this->admin->createSampleType(['code' => 'SPL-AL', 'name' => 'Air Limbah', 'category' => 'air-limbah']);
        $parameter = $this->admin->createParameter(['code' => 'PAR-BOD', 'name' => 'BOD']);

        $this->expectException(EnvlabException::class);
        $this->expectExceptionMessage('Isi rentang nilai');

        $this->admin->setQualityStandard($sampel->id, $parameter->id, []);
    }

    #[Test]
    public function baku_mutu_kombinasi_sampel_parameter_yang_sama_menimpa_bukan_menggandakan(): void
    {
        $sampel = $this->admin->createSampleType(['code' => 'SPL-AL', 'name' => 'Air Limbah', 'category' => 'air-limbah']);
        $parameter = $this->admin->createParameter(['code' => 'PAR-BOD', 'name' => 'BOD']);

        $this->admin->setQualityStandard($sampel->id, $parameter->id, ['max_value' => 30]);
        $this->admin->setQualityStandard($sampel->id, $parameter->id, ['max_value' => 25]);

        $this->assertSame(1, QualityStandard::query()->count());
        $this->assertSame('25.0000', QualityStandard::query()->first()->max_value);
    }
}
