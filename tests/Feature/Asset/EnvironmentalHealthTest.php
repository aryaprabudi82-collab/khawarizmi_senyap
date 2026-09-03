<?php

namespace Tests\Feature\Asset;

use App\Modules\Asset\Models\EnvironmentalMeasurement;
use App\Modules\Asset\Models\PestControlVisit;
use App\Modules\Asset\Services\EnvironmentalHealthService;
use App\Modules\Platform\Database\Seeders\PermissionCatalogSeeder;
use App\Modules\Platform\Database\Seeders\RoleSeeder;
use App\Modules\Platform\Models\Role;
use App\Modules\Platform\Models\User;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class EnvironmentalHealthTest extends TestCase
{
    use RefreshDatabase;

    private EnvironmentalHealthService $kesling;
    private User $petugas;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([PermissionCatalogSeeder::class, RoleSeeder::class, ReferenceDataSeeder::class]);

        $this->kesling = app(EnvironmentalHealthService::class);

        $this->petugas = User::query()->create([
            'username' => 'uji-kesling', 'name' => 'Petugas Kesling Uji', 'password' => 'password', 'is_active' => true,
        ]);
        $this->petugas->roles()->attach(Role::query()->where('code', 'petugas-kesling')->firstOrFail());
    }

    #[Test]
    public function pengukuran_limbah_b3_tercatat(): void
    {
        $pengukuran = $this->kesling->recordMeasurement([
            'category' => EnvironmentalMeasurement::CATEGORY_LIMBAH_B3_PADAT,
            'measured_on' => now()->toDateString(),
            'quantity' => 12.5,
            'unit' => 'kg',
        ], $this->petugas);

        $this->assertSame('limbah-b3-padat', $pengukuran->category);
        $this->assertEqualsWithDelta(12.5, (float) $pengukuran->quantity, 0.001);
        $this->assertSame($this->petugas->id, $pengukuran->recorded_by);
    }

    #[Test]
    public function pengukuran_mutu_air_limbah_menyimpan_parameter(): void
    {
        $pengukuran = $this->kesling->recordMeasurement([
            'category' => EnvironmentalMeasurement::CATEGORY_MUTU_AIR_LIMBAH,
            'parameter' => 'BOD',
            'measured_on' => now()->toDateString(),
            'quantity' => 24.3,
            'unit' => 'mg/L',
        ], $this->petugas);

        $this->assertSame('BOD', $pengukuran->parameter);
    }

    #[Test]
    public function beberapa_parameter_mutu_air_pada_tanggal_sama_tersimpan_terpisah(): void
    {
        $this->kesling->recordMeasurement([
            'category' => EnvironmentalMeasurement::CATEGORY_MUTU_AIR_LIMBAH, 'parameter' => 'BOD',
            'measured_on' => '2026-09-01', 'quantity' => 20, 'unit' => 'mg/L',
        ], $this->petugas);
        $this->kesling->recordMeasurement([
            'category' => EnvironmentalMeasurement::CATEGORY_MUTU_AIR_LIMBAH, 'parameter' => 'COD',
            'measured_on' => '2026-09-01', 'quantity' => 45, 'unit' => 'mg/L',
        ], $this->petugas);

        $this->assertSame(2, EnvironmentalMeasurement::query()->where('measured_on', '2026-09-01')->count());
    }

    #[Test]
    public function kunjungan_pest_control_tercatat(): void
    {
        $kunjungan = $this->kesling->recordPestControlVisit([
            'visited_on' => now()->toDateString(),
            'location' => 'Dapur Gizi',
            'findings' => 'Ditemukan jejak tikus di gudang kering',
            'action_taken' => 'Pemasangan perangkap dan penutupan celah dinding',
            'vendor' => 'PT Bersih Hama',
        ], $this->petugas);

        $this->assertInstanceOf(PestControlVisit::class, $kunjungan);
        $this->assertSame('Dapur Gizi', $kunjungan->location);
    }

    #[Test]
    public function layar_kesling_hanya_untuk_petugas_kesling(): void
    {
        $this->actingAs($this->petugas)->get(route('asset.kesling.index'))->assertOk();

        $dokter = User::query()->create([
            'username' => 'uji-dokter-kesling', 'name' => 'Dokter Uji', 'password' => 'password', 'is_active' => true,
        ]);
        $dokter->roles()->attach(Role::query()->where('code', 'dokter')->firstOrFail());

        $this->actingAs($dokter)->get(route('asset.kesling.index'))->assertForbidden();
    }
}
