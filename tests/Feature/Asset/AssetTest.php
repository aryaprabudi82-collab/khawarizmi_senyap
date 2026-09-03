<?php

namespace Tests\Feature\Asset;

use App\Modules\Asset\Models\Asset;
use App\Modules\Asset\Models\MaintenanceRequest;
use App\Modules\Asset\Services\AssetException;
use App\Modules\Asset\Services\AssetService;
use App\Modules\Asset\Services\MaintenanceService;
use App\Modules\Platform\Database\Seeders\PermissionCatalogSeeder;
use App\Modules\Platform\Database\Seeders\RoleSeeder;
use App\Modules\Platform\Models\Role;
use App\Modules\Platform\Models\User;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class AssetTest extends TestCase
{
    use RefreshDatabase;

    private AssetService $assets;
    private MaintenanceService $maintenance;
    private User $petugas;
    private Asset $aset;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([PermissionCatalogSeeder::class, RoleSeeder::class, ReferenceDataSeeder::class]);

        $this->assets = app(AssetService::class);
        $this->maintenance = app(MaintenanceService::class);

        $this->petugas = User::query()->create([
            'username' => 'uji-aset', 'name' => 'Petugas Aset Uji', 'password' => 'password', 'is_active' => true,
        ]);
        $this->petugas->roles()->attach(Role::query()->where('code', 'petugas-aset')->firstOrFail());

        $kategori = $this->assets->createCategory(['code' => 'ELK', 'name' => 'Elektronik']);
        $this->aset = $this->assets->createAsset(['name' => 'AC Split 1 PK', 'category_id' => $kategori->id]);
    }

    #[Test]
    public function aset_baru_mendapat_nomor_dan_berstatus_aktif(): void
    {
        $this->assertMatchesRegularExpression('/^AST-\d{4}-\d{5}$/', $this->aset->asset_number);
        $this->assertSame(Asset::STATUS_AKTIF, $this->aset->status);
        $this->assertSame(Asset::CONDITION_BAIK, $this->aset->condition);
    }

    #[Test]
    public function laporan_kerusakan_mengubah_status_aset_saat_mulai_dikerjakan(): void
    {
        $permintaan = $this->maintenance->report($this->aset, 'AC tidak dingin', $this->petugas->id);
        $this->assertSame(MaintenanceRequest::STATUS_DIAJUKAN, $permintaan->status);
        $this->assertSame(Asset::STATUS_AKTIF, $this->aset->refresh()->status, 'Status aset belum berubah sebelum dikerjakan.');

        $this->maintenance->start($permintaan, $this->petugas);

        $this->assertSame(MaintenanceRequest::STATUS_DIKERJAKAN, $permintaan->refresh()->status);
        $this->assertSame(Asset::STATUS_DALAM_PERBAIKAN, $this->aset->refresh()->status);
    }

    #[Test]
    public function penyelesaian_perbaikan_mengembalikan_aset_ke_aktif_dengan_kondisi_terbaru(): void
    {
        $permintaan = $this->maintenance->start(
            $this->maintenance->report($this->aset, 'AC bocor', $this->petugas->id),
            $this->petugas
        );

        $this->maintenance->complete($permintaan, 'Sudah diganti kompresor.', 'rusak-ringan');

        $this->assertSame(MaintenanceRequest::STATUS_SELESAI, $permintaan->refresh()->status);
        $this->assertSame(Asset::STATUS_AKTIF, $this->aset->refresh()->status);
        $this->assertSame('rusak-ringan', $this->aset->condition);
    }

    #[Test]
    public function aset_tidak_boleh_punya_dua_permintaan_perbaikan_aktif_sekaligus(): void
    {
        $this->maintenance->report($this->aset, 'AC tidak dingin', $this->petugas->id);

        $this->expectException(AssetException::class);
        $this->expectExceptionMessage('sudah punya permintaan perbaikan');

        $this->maintenance->report($this->aset, 'AC berisik', $this->petugas->id);
    }

    #[Test]
    public function permintaan_yang_sudah_dikerjakan_tidak_bisa_ditolak(): void
    {
        $permintaan = $this->maintenance->start(
            $this->maintenance->report($this->aset, 'AC mati total', $this->petugas->id),
            $this->petugas
        );

        $this->expectException(AssetException::class);

        $this->maintenance->reject($permintaan, 'Coba tolak setelah dikerjakan');
    }

    #[Test]
    public function permintaan_yang_ditolak_membebaskan_aset_untuk_laporan_baru(): void
    {
        $pertama = $this->maintenance->report($this->aset, 'AC tidak dingin', $this->petugas->id);
        $this->maintenance->reject($pertama, 'Ternyata cuma perlu dibersihkan filternya');

        $kedua = $this->maintenance->report($this->aset, 'Filter kotor, mohon dibersihkan', $this->petugas->id);

        $this->assertSame(MaintenanceRequest::STATUS_DIAJUKAN, $kedua->status);
        $this->assertNotSame($pertama->id, $kedua->id);
    }

    #[Test]
    public function layar_aset_hanya_untuk_petugas_aset(): void
    {
        $this->actingAs($this->petugas)->get(route('asset.index'))->assertOk();
        $this->actingAs($this->petugas)->get(route('asset.pemeliharaan.index'))->assertOk();

        $dokter = User::query()->create([
            'username' => 'uji-dokter-aset', 'name' => 'Dokter Uji', 'password' => 'password', 'is_active' => true,
        ]);
        $dokter->roles()->attach(Role::query()->where('code', 'dokter')->firstOrFail());

        $this->actingAs($dokter)->get(route('asset.index'))->assertForbidden();
    }
}
