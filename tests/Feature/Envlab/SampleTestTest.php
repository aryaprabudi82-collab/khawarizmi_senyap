<?php

namespace Tests\Feature\Envlab;

use App\Modules\Envlab\Models\Customer;
use App\Modules\Envlab\Models\SampleTest;
use App\Modules\Envlab\Models\SampleType;
use App\Modules\Envlab\Models\TestParameter;
use App\Modules\Envlab\Services\EnvlabException;
use App\Modules\Envlab\Services\EnvlabMasterDataService;
use App\Modules\Envlab\Services\SampleTestService;
use App\Modules\Platform\Database\Seeders\PermissionCatalogSeeder;
use App\Modules\Platform\Database\Seeders\RoleSeeder;
use App\Modules\Platform\Models\Role;
use App\Modules\Platform\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SampleTestTest extends TestCase
{
    use RefreshDatabase;

    private SampleTestService $tests;
    private EnvlabMasterDataService $master;
    private User $petugas;
    private User $penyelia;

    private Customer $pelanggan;
    private SampleType $jenisSampel;
    private TestParameter $parameterKuantitatif;
    private TestParameter $parameterKualitatif;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([PermissionCatalogSeeder::class, RoleSeeder::class]);

        $this->tests = app(SampleTestService::class);
        $this->master = app(EnvlabMasterDataService::class);

        $this->petugas = User::query()->create([
            'username' => 'uji-envlab-petugas', 'name' => 'Petugas Uji', 'password' => 'password', 'is_active' => true,
        ]);
        $this->petugas->roles()->attach(Role::query()->where('code', 'petugas-lab-kesling')->firstOrFail());

        $this->penyelia = User::query()->create([
            'username' => 'uji-envlab-penyelia', 'name' => 'Penyelia Uji', 'password' => 'password', 'is_active' => true,
        ]);
        $this->penyelia->roles()->attach(Role::query()->where('code', 'penyelia-lab-kesling')->firstOrFail());

        $this->pelanggan = $this->master->createCustomer(['code' => 'PLG-001', 'name' => 'PT Uji', 'kind' => 'eksternal', 'is_active' => true]);
        $this->jenisSampel = $this->master->createSampleType(['code' => 'SPL-AL', 'name' => 'Air Limbah', 'category' => 'air-limbah', 'is_active' => true]);
        $this->parameterKuantitatif = $this->master->createParameter(['code' => 'PAR-BOD', 'name' => 'BOD', 'unit' => 'mg/L', 'is_active' => true]);
        $this->parameterKualitatif = $this->master->createParameter(['code' => 'PAR-SALM', 'name' => 'Salmonella', 'is_active' => true]);

        $this->master->setQualityStandard($this->jenisSampel->id, $this->parameterKuantitatif->id, ['max_value' => 30]);
        $this->master->setQualityStandard($this->jenisSampel->id, $this->parameterKualitatif->id, ['qualitative_standard' => 'Negatif']);
    }

    #[Test]
    public function permintaan_tersimpan_dan_menyalin_baku_mutu_ke_item(): void
    {
        $permintaan = $this->tests->request(
            $this->pelanggan->id, $this->jenisSampel->id,
            [$this->parameterKuantitatif->id, $this->parameterKualitatif->id],
            'Titik outlet IPAL', $this->petugas,
        );

        $this->assertStringStartsWith('ENV-' . now()->format('Ymd'), $permintaan->request_number);
        $this->assertSame(SampleTest::STATUS_DIMINTA, $permintaan->status);
        $this->assertSame(2, $permintaan->items()->count());

        $itemBod = $permintaan->items()->where('parameter_id', $this->parameterKuantitatif->id)->first();
        $this->assertSame('30.0000', $itemBod->standard_max);
    }

    #[Test]
    public function permintaan_tanpa_parameter_ditolak(): void
    {
        $this->expectException(EnvlabException::class);
        $this->expectExceptionMessage('minimal satu parameter');

        $this->tests->request($this->pelanggan->id, $this->jenisSampel->id, [], null, $this->petugas);
    }

    #[Test]
    public function sampel_bisa_ditolak_dari_status_diminta(): void
    {
        $permintaan = $this->buatPermintaan();

        $ditolak = $this->tests->reject($permintaan, 'Volume sampel tidak cukup untuk diuji.');

        $this->assertSame(SampleTest::STATUS_DITOLAK, $ditolak->status);
        $this->assertSame('Volume sampel tidak cukup untuk diuji.', $ditolak->rejection_reason);
    }

    #[Test]
    public function sampel_diterima_dan_ditugaskan(): void
    {
        $permintaan = $this->buatPermintaan();

        $diterima = $this->tests->accept($permintaan, 'Analis Sari', $this->penyelia);

        $this->assertSame(SampleTest::STATUS_DIPROSES, $diterima->status);
        $this->assertSame('Analis Sari', $diterima->assigned_to_name);
    }

    #[Test]
    public function hasil_kuantitatif_melebihi_baku_mutu_ditandai(): void
    {
        $permintaan = $this->tests->accept($this->buatPermintaan(), 'Analis Sari', $this->penyelia);
        $item = $permintaan->items()->where('parameter_id', $this->parameterKuantitatif->id)->first();

        $normal = $this->tests->enterResult($item, 20.0, null, $this->petugas);
        $this->assertFalse($normal->is_exceeded);

        $melebihi = $this->tests->enterResult($item->refresh(), 45.0, null, $this->petugas);
        $this->assertTrue($melebihi->is_exceeded);
    }

    #[Test]
    public function hasil_kualitatif_yang_menyimpang_ditandai(): void
    {
        $permintaan = $this->tests->accept($this->buatPermintaan(), 'Analis Sari', $this->penyelia);
        $item = $permintaan->items()->where('parameter_id', $this->parameterKualitatif->id)->first();

        $hasil = $this->tests->enterResult($item, null, 'Positif', $this->petugas);

        $this->assertTrue($hasil->is_exceeded);
    }

    #[Test]
    public function status_pindah_hasil_tersedia_otomatis_begitu_semua_item_terisi(): void
    {
        $permintaan = $this->tests->accept($this->buatPermintaan(), 'Analis Sari', $this->penyelia);
        $itemBod = $permintaan->items()->where('parameter_id', $this->parameterKuantitatif->id)->first();
        $itemSalm = $permintaan->items()->where('parameter_id', $this->parameterKualitatif->id)->first();

        $this->tests->enterResult($itemBod, 20.0, null, $this->petugas);
        $this->assertSame(SampleTest::STATUS_DIPROSES, $permintaan->fresh()->status);

        $this->tests->enterResult($itemSalm, null, 'Negatif', $this->petugas);
        $this->assertSame(SampleTest::STATUS_HASIL_TERSEDIA, $permintaan->fresh()->status);
    }

    #[Test]
    public function alur_lengkap_dari_permintaan_sampai_selesai(): void
    {
        $permintaan = $this->buatPermintaan();
        $permintaan = $this->tests->accept($permintaan, 'Analis Sari', $this->penyelia);

        foreach ($permintaan->items as $item) {
            $this->tests->enterResult($item, $item->parameter_id === $this->parameterKuantitatif->id ? 20.0 : null,
                $item->parameter_id === $this->parameterKualitatif->id ? 'Negatif' : null, $this->petugas);
        }

        $terverifikasi = $this->tests->verify($permintaan->fresh(), $this->penyelia);
        $this->assertSame(SampleTest::STATUS_TERVERIFIKASI, $terverifikasi->status);

        $selesai = $this->tests->validateResult($terverifikasi, $this->penyelia);
        $this->assertSame(SampleTest::STATUS_SELESAI, $selesai->status);
        $this->assertSame($this->penyelia->id, $selesai->validated_by);
    }

    #[Test]
    public function verifikasi_sebelum_hasil_lengkap_ditolak(): void
    {
        $permintaan = $this->tests->accept($this->buatPermintaan(), 'Analis Sari', $this->penyelia);

        $this->expectException(EnvlabException::class);

        $this->tests->verify($permintaan, $this->penyelia);
    }

    #[Test]
    public function pembayaran_tercatat_lunas(): void
    {
        $permintaan = $this->buatPermintaan();

        $lunas = $this->tests->markPaid($permintaan, 500000);

        $this->assertSame(SampleTest::PAYMENT_LUNAS, $lunas->payment_status);
        $this->assertSame('500000.00', $lunas->price);
    }

    #[Test]
    public function pembayaran_ganda_ditolak(): void
    {
        $permintaan = $this->tests->markPaid($this->buatPermintaan(), 500000);

        $this->expectException(EnvlabException::class);
        $this->expectExceptionMessage('sudah lunas');

        $this->tests->markPaid($permintaan, 500000);
    }

    // ------------------------------------------------------------------ bantu

    private function buatPermintaan(): SampleTest
    {
        return $this->tests->request(
            $this->pelanggan->id, $this->jenisSampel->id,
            [$this->parameterKuantitatif->id, $this->parameterKualitatif->id],
            null, $this->petugas,
        );
    }
}
