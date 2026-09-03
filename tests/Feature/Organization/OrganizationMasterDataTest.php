<?php

namespace Tests\Feature\Organization;

use App\Modules\Organization\Models\Practitioner;
use App\Modules\Organization\Models\Unit;
use App\Modules\Organization\Services\OrganizationAdminService;
use App\Modules\Organization\Services\OrganizationException;
use App\Modules\Platform\Database\Seeders\PermissionCatalogSeeder;
use App\Modules\Platform\Database\Seeders\RoleSeeder;
use App\Modules\Platform\Models\Role;
use App\Modules\Platform\Models\User;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class OrganizationMasterDataTest extends TestCase
{
    use RefreshDatabase;

    private User $adminMaster;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([PermissionCatalogSeeder::class, RoleSeeder::class, ReferenceDataSeeder::class]);

        $this->adminMaster = User::query()->create([
            'username' => 'uji-org-master', 'name' => 'Admin Master Uji', 'password' => 'password', 'is_active' => true,
        ]);
        $this->adminMaster->roles()->attach(Role::query()->where('code', 'admin-master')->firstOrFail());
    }

    #[Test]
    public function admin_master_dapat_menambah_unit_baru(): void
    {
        $this->actingAs($this->adminMaster)
            ->post(route('master.unit.simpan'), [
                'code' => 'POL-JANTUNG', 'name' => 'Poliklinik Jantung', 'kind' => 'poliklinik', 'daily_quota' => 40,
            ])
            ->assertRedirect()
            ->assertSessionHas('sukses');

        $this->assertDatabaseHas('organization.units', ['code' => 'POL-JANTUNG', 'daily_quota' => 40]);
    }

    #[Test]
    public function admin_master_dapat_menambah_praktisi_dengan_unit_utama(): void
    {
        $poli = Unit::query()->where('code', 'POL-UMUM')->firstOrFail();

        $this->actingAs($this->adminMaster)
            ->post(route('master.praktisi.simpan'), [
                'code' => 'DR099', 'title' => 'dr.', 'name' => 'Rina Kartika', 'specialty' => 'Umum',
                'unit_id' => $poli->id,
            ])
            ->assertRedirect()
            ->assertSessionHas('sukses');

        $praktisi = Practitioner::query()->where('code', 'DR099')->firstOrFail();
        $this->assertTrue($praktisi->units->contains('id', $poli->id));
        $this->assertTrue($praktisi->units->firstWhere('id', $poli->id)->pivot->is_primary);
    }

    #[Test]
    public function menyunting_praktisi_menyamakan_unit_sesuai_yang_dipilih(): void
    {
        $poliA = Unit::query()->where('code', 'POL-UMUM')->firstOrFail();
        $poliB = Unit::query()->where('code', 'POL-ANAK')->firstOrFail();
        $praktisi = Practitioner::query()->where('code', 'DR001')->firstOrFail();

        $this->actingAs($this->adminMaster)
            ->post(route('master.praktisi.perbarui', $praktisi), [
                'name' => $praktisi->name,
                'units' => [$poliB->id],
                'primary_unit_id' => $poliB->id,
                'is_active' => '1',
            ])
            ->assertRedirect()
            ->assertSessionHas('sukses');

        $praktisi->refresh();
        $this->assertFalse($praktisi->units->contains('id', $poliA->id));
        $this->assertTrue($praktisi->units->contains('id', $poliB->id));
    }

    #[Test]
    public function menonaktifkan_praktisi_tersimpan(): void
    {
        $praktisi = Practitioner::query()->where('code', 'DR001')->firstOrFail();

        $this->actingAs($this->adminMaster)
            ->post(route('master.praktisi.perbarui', $praktisi), [
                'name' => $praktisi->name,
                'is_active' => '0',
            ]);

        $this->assertFalse($praktisi->fresh()->is_active);
    }

    #[Test]
    public function jadwal_praktik_bisa_ditambah_lewat_http(): void
    {
        $praktisi = Practitioner::query()->where('code', 'DR001')->firstOrFail();
        $unit = $praktisi->units->first();

        $this->actingAs($this->adminMaster)
            ->post(route('master.praktisi.jadwal.simpan', $praktisi), [
                'unit_id' => $unit->id, 'day_of_week' => 1, 'start_time' => '08:00', 'end_time' => '12:00',
            ])
            ->assertRedirect()
            ->assertSessionHas('sukses');

        $this->assertDatabaseHas('organization.practice_schedules', [
            'practitioner_id' => $praktisi->id, 'unit_id' => $unit->id, 'day_of_week' => 1,
        ]);
    }

    #[Test]
    public function jadwal_yang_persis_sama_ditolak(): void
    {
        $praktisi = Practitioner::query()->where('code', 'DR001')->firstOrFail();
        $unit = $praktisi->units->first();
        $admin = app(OrganizationAdminService::class);

        $admin->addSchedule($praktisi, [
            'unit_id' => $unit->id, 'day_of_week' => 1, 'start_time' => '08:00', 'end_time' => '12:00',
        ]);

        $this->expectException(OrganizationException::class);
        $admin->addSchedule($praktisi, [
            'unit_id' => $unit->id, 'day_of_week' => 1, 'start_time' => '08:00', 'end_time' => '11:00',
        ]);
    }

    #[Test]
    public function jam_selesai_harus_setelah_jam_mulai(): void
    {
        $praktisi = Practitioner::query()->where('code', 'DR001')->firstOrFail();
        $unit = $praktisi->units->first();

        $this->actingAs($this->adminMaster)
            ->post(route('master.praktisi.jadwal.simpan', $praktisi), [
                'unit_id' => $unit->id, 'day_of_week' => 1, 'start_time' => '12:00', 'end_time' => '08:00',
            ])
            ->assertSessionHasErrors('end_time');
    }

    #[Test]
    public function jadwal_praktik_bisa_dihapus(): void
    {
        $praktisi = Practitioner::query()->where('code', 'DR001')->firstOrFail();
        $unit = $praktisi->units->first();
        $jadwal = app(OrganizationAdminService::class)->addSchedule($praktisi, [
            'unit_id' => $unit->id, 'day_of_week' => 2, 'start_time' => '08:00', 'end_time' => '12:00',
        ]);

        $this->actingAs($this->adminMaster)
            ->delete(route('master.jadwal.hapus', $jadwal))
            ->assertRedirect()
            ->assertSessionHas('sukses');

        $this->assertDatabaseMissing('organization.practice_schedules', ['id' => $jadwal->id]);
    }

    #[Test]
    public function petugas_lain_tidak_bisa_mengubah_data_organisasi(): void
    {
        $dokter = User::query()->create([
            'username' => 'uji-dokter-org', 'name' => 'Dokter Uji', 'password' => 'password', 'is_active' => true,
        ]);
        $dokter->roles()->attach(Role::query()->where('code', 'dokter')->firstOrFail());

        $this->actingAs($dokter)
            ->post(route('master.unit.simpan'), ['code' => 'X', 'name' => 'X', 'kind' => 'poliklinik'])
            ->assertForbidden();
    }
}
