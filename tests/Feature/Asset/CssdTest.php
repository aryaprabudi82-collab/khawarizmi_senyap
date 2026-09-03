<?php

namespace Tests\Feature\Asset;

use App\Modules\Asset\Models\CssdCirculation;
use App\Modules\Asset\Models\CssdItem;
use App\Modules\Asset\Services\AssetException;
use App\Modules\Asset\Services\CssdService;
use App\Modules\Organization\Models\Unit;
use App\Modules\Platform\Database\Seeders\PermissionCatalogSeeder;
use App\Modules\Platform\Database\Seeders\RoleSeeder;
use App\Modules\Platform\Models\Role;
use App\Modules\Platform\Models\User;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class CssdTest extends TestCase
{
    use RefreshDatabase;

    private CssdService $cssd;
    private User $petugas;
    private CssdItem $set;
    private Unit $unit;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([PermissionCatalogSeeder::class, RoleSeeder::class, ReferenceDataSeeder::class]);

        $this->cssd = app(CssdService::class);

        $this->petugas = User::query()->create([
            'username' => 'uji-cssd', 'name' => 'Petugas CSSD Uji', 'password' => 'password', 'is_active' => true,
        ]);
        $this->petugas->roles()->attach(Role::query()->where('code', 'petugas-cssd')->firstOrFail());

        $this->set = CssdItem::query()->create(['code' => 'SET001', 'name' => 'Set Bedah Minor', 'is_active' => true]);
        $this->unit = Unit::query()->where('code', 'POL-UMUM')->firstOrFail();
    }

    #[Test]
    public function set_diterima_berstatus_kotor_dengan_nomor_berformat_cssd_tahun_urut(): void
    {
        $sirkulasi = $this->terima();

        $this->assertMatchesRegularExpression('/^CSSD-\d{4}-\d{5}$/', $sirkulasi->circulation_number);
        $this->assertSame(CssdCirculation::STATUS_KOTOR, $sirkulasi->status);
        $this->assertSame($this->unit->name, $sirkulasi->unit_name);
    }

    #[Test]
    public function alur_lengkap_kotor_diproses_steril_didistribusikan(): void
    {
        $sirkulasi = $this->terima();

        $sirkulasi = $this->cssd->startProcessing($sirkulasi);
        $this->assertSame(CssdCirculation::STATUS_DIPROSES, $sirkulasi->status);
        $this->assertNotNull($sirkulasi->processed_at);

        $sirkulasi = $this->cssd->markSterile($sirkulasi, 'autoklaf-uap');
        $this->assertSame(CssdCirculation::STATUS_STERIL, $sirkulasi->status);
        $this->assertSame('autoklaf-uap', $sirkulasi->sterilization_method);

        $sirkulasi = $this->cssd->distribute($sirkulasi);
        $this->assertSame(CssdCirculation::STATUS_DIDISTRIBUSIKAN, $sirkulasi->status);
        $this->assertNotNull($sirkulasi->distributed_at);
    }

    #[Test]
    public function tidak_bisa_lompat_langsung_dari_kotor_ke_steril(): void
    {
        $sirkulasi = $this->terima();

        $this->expectException(AssetException::class);
        $this->expectExceptionMessage("harus 'diproses' lebih dulu");

        $this->cssd->markSterile($sirkulasi, 'autoklaf-uap');
    }

    #[Test]
    public function tidak_bisa_distribusi_sebelum_steril(): void
    {
        $sirkulasi = $this->cssd->startProcessing($this->terima());

        $this->expectException(AssetException::class);

        $this->cssd->distribute($sirkulasi);
    }

    #[Test]
    public function set_yang_sama_bisa_berputar_lagi_setelah_didistribusikan(): void
    {
        $pertama = $this->cssd->distribute($this->cssd->markSterile($this->cssd->startProcessing($this->terima()), 'autoklaf-uap'));

        $kedua = $this->terima();

        $this->assertSame(CssdCirculation::STATUS_KOTOR, $kedua->status);
        $this->assertNotSame($pertama->id, $kedua->id);
        $this->assertSame(2, CssdCirculation::query()->where('cssd_item_id', $this->set->id)->count());
    }

    #[Test]
    public function layar_cssd_hanya_untuk_petugas_cssd(): void
    {
        $this->actingAs($this->petugas)->get(route('asset.cssd.index'))->assertOk();

        $dokter = User::query()->create([
            'username' => 'uji-dokter-cssd', 'name' => 'Dokter Uji', 'password' => 'password', 'is_active' => true,
        ]);
        $dokter->roles()->attach(Role::query()->where('code', 'dokter')->firstOrFail());

        $this->actingAs($dokter)->get(route('asset.cssd.index'))->assertForbidden();
    }

    private function terima(): CssdCirculation
    {
        return $this->cssd->receive($this->set, $this->unit->id, $this->unit->name, $this->petugas);
    }
}
