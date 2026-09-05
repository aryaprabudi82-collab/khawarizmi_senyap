<?php

namespace Tests\Feature\Asset;

use App\Modules\Asset\Models\Asset;
use App\Modules\Asset\Models\AssetLocation;
use App\Modules\Asset\Models\MaintenanceSchedule;
use App\Modules\Asset\Services\AssetException;
use App\Modules\Asset\Services\AssetMaintenanceScheduleService;
use App\Modules\Asset\Services\AssetService;
use App\Modules\Platform\Database\Seeders\PermissionCatalogSeeder;
use App\Modules\Platform\Database\Seeders\RoleSeeder;
use App\Modules\Platform\Models\Role;
use App\Modules\Platform\Models\User;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Domain G item D (terakhir): pemeliharaan_inventaris +
 * pemeliharaan_gedung — jadwal preventif interval berulang otomatis,
 * beda dari perbaikan reaktif MaintenanceRequest yang sudah ada.
 */
class MaintenanceScheduleTest extends TestCase
{
    use RefreshDatabase;

    private AssetService $assets;
    private AssetMaintenanceScheduleService $schedules;
    private User $petugas;
    private Asset $aset;
    private AssetLocation $gedung;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([PermissionCatalogSeeder::class, RoleSeeder::class, ReferenceDataSeeder::class]);

        $this->assets = app(AssetService::class);
        $this->schedules = app(AssetMaintenanceScheduleService::class);

        $this->petugas = User::query()->create([
            'username' => 'uji-jadwal-aset', 'name' => 'Petugas Aset Uji', 'password' => 'password', 'is_active' => true,
        ]);
        $this->petugas->roles()->attach(Role::query()->where('code', 'petugas-aset')->firstOrFail());

        $kategori = $this->assets->createCategory(['code' => 'ELK', 'name' => 'Elektronik']);
        $this->aset = $this->assets->createAsset(['name' => 'AC Split 1 PK', 'category_id' => $kategori->id]);
        $this->gedung = $this->assets->createLocation(['code' => 'GD-A', 'name' => 'Gedung A']);
    }

    #[Test]
    public function jadwal_aset_dibuat_dengan_next_due_date_dari_interval(): void
    {
        Carbon::setTestNow('2026-01-01');

        $jadwal = $this->schedules->create([
            'asset_id' => $this->aset->id, 'title' => 'Servis AC', 'interval_months' => 3,
        ], $this->petugas->id);

        $this->assertSame($this->aset->id, $jadwal->asset_id);
        $this->assertNull($jadwal->location_id);
        $this->assertSame('2026-04-01', $jadwal->next_due_date->toDateString());
        $this->assertMatchesRegularExpression('/^JDW-\d{4}-\d{5}$/', $jadwal->schedule_number);

        Carbon::setTestNow();
    }

    #[Test]
    public function jadwal_gedung_dibuat_dengan_location_id_bukan_asset_id(): void
    {
        $jadwal = $this->schedules->create([
            'location_id' => $this->gedung->id, 'title' => 'Servis Genset Gedung', 'interval_months' => 6,
        ], $this->petugas->id);

        $this->assertNull($jadwal->asset_id);
        $this->assertSame($this->gedung->id, $jadwal->location_id);
        $this->assertSame('Gedung A', $jadwal->targetLabel());
    }

    #[Test]
    public function jadwal_tanpa_target_ditolak(): void
    {
        $this->expectException(AssetException::class);
        $this->expectExceptionMessage('menyasar aset atau lokasi');

        $this->schedules->create(['title' => 'Tanpa target', 'interval_months' => 1], $this->petugas->id);
    }

    #[Test]
    public function pelaksanaan_dicatat_menghitung_ulang_jadwal_berikutnya(): void
    {
        $jadwal = $this->schedules->create([
            'asset_id' => $this->aset->id, 'title' => 'Servis AC', 'interval_months' => 3,
        ], $this->petugas->id);

        $selesai = $this->schedules->recordCompletion($jadwal, $this->petugas, 'Sudah dibersihkan', '2026-06-15');

        $this->assertSame('2026-06-15', $selesai->last_performed_at->toDateString());
        $this->assertSame('2026-09-15', $selesai->next_due_date->toDateString());
        $this->assertCount(1, $jadwal->logs);
    }

    #[Test]
    public function jadwal_nonaktif_tidak_bisa_dicatat_pelaksanaannya(): void
    {
        $jadwal = $this->schedules->create([
            'asset_id' => $this->aset->id, 'title' => 'Servis AC', 'interval_months' => 3,
        ], $this->petugas->id);

        $this->schedules->deactivate($jadwal);

        $this->expectException(AssetException::class);
        $this->schedules->recordCompletion($jadwal->fresh(), $this->petugas, null);
    }

    #[Test]
    public function jadwal_yang_lewat_tanggal_terdeteksi_terlambat(): void
    {
        $jadwal = $this->schedules->create([
            'asset_id' => $this->aset->id, 'title' => 'Servis AC', 'interval_months' => 1,
        ], $this->petugas->id);

        $jadwal->update(['next_due_date' => now()->subDay()->toDateString()]);

        $this->assertTrue($jadwal->refresh()->isOverdue());
    }

    #[Test]
    public function layar_jadwal_hanya_untuk_petugas_aset(): void
    {
        $this->actingAs($this->petugas)->get(route('asset.jadwal.index'))->assertOk();

        $dokter = User::query()->create([
            'username' => 'uji-dokter-jadwal-aset', 'name' => 'Dokter Uji', 'password' => 'password', 'is_active' => true,
        ]);
        $dokter->roles()->attach(Role::query()->where('code', 'dokter')->firstOrFail());

        $this->actingAs($dokter)->get(route('asset.jadwal.index'))->assertForbidden();
    }

    #[Test]
    public function jadwal_bisa_dibuat_lewat_http(): void
    {
        $this->actingAs($this->petugas)->post(route('asset.jadwal.simpan'), [
            'target_type' => 'aset', 'asset_id' => $this->aset->id, 'title' => 'Kalibrasi Tahunan', 'interval_months' => 12,
        ])->assertRedirect();

        $this->assertDatabaseHas('asset.maintenance_schedules', ['asset_id' => $this->aset->id, 'title' => 'Kalibrasi Tahunan']);
    }
}
