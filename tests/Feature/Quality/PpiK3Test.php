<?php

namespace Tests\Feature\Quality;

use App\Modules\Platform\Database\Seeders\PermissionCatalogSeeder;
use App\Modules\Platform\Database\Seeders\RoleSeeder;
use App\Modules\Platform\Models\Role;
use App\Modules\Platform\Models\User;
use App\Modules\Quality\Models\K3Incident;
use App\Modules\Quality\Models\PpiAudit;
use App\Modules\Quality\Services\K3IncidentService;
use App\Modules\Quality\Services\PpiAuditService;
use App\Modules\Quality\Services\QualityException;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class PpiK3Test extends TestCase
{
    use RefreshDatabase;

    private PpiAuditService $ppi;
    private K3IncidentService $k3;
    private User $adminMutu;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([PermissionCatalogSeeder::class, RoleSeeder::class, ReferenceDataSeeder::class]);

        $this->ppi = app(PpiAuditService::class);
        $this->k3 = app(K3IncidentService::class);

        $this->adminMutu = User::query()->create([
            'username' => 'uji-ppi-k3', 'name' => 'Admin Mutu Uji', 'password' => 'password', 'is_active' => true,
        ]);
        $this->adminMutu->roles()->attach(Role::query()->where('code', 'admin-mutu')->firstOrFail());
    }

    #[Test]
    public function audit_ppi_tercatat_dengan_tingkat_kepatuhan(): void
    {
        $audit = $this->ppi->record([
            'audit_type' => 'kepatuhan-apd',
            'audited_on' => now()->toDateString(),
            'compliance_rate' => 87.5,
            'findings' => 'Dua dari enam belas petugas belum memakai masker N95 saat tindakan aerosol.',
        ], $this->adminMutu);

        $this->assertSame('kepatuhan-apd', $audit->audit_type);
        $this->assertEqualsWithDelta(87.5, (float) $audit->compliance_rate, 0.001);
        $this->assertSame($this->adminMutu->id, $audit->auditor_id);
    }

    #[Test]
    public function insiden_k3_tercatat_berformat_k3_tahun_urut(): void
    {
        $insiden = $this->laporkan();

        $this->assertMatchesRegularExpression('/^K3-\d{4}-\d{5}$/', $insiden->incident_number);
        $this->assertSame(K3Incident::STATUS_DILAPORKAN, $insiden->status);
    }

    #[Test]
    public function insiden_k3_tidak_bisa_ditutup_sebelum_ditinjau(): void
    {
        $insiden = $this->laporkan();

        $this->expectException(QualityException::class);
        $this->expectExceptionMessage('harus ditinjau lebih dulu');

        $this->k3->close($insiden, 'Perbaikan SOP penanganan jarum bekas');
    }

    #[Test]
    public function alur_lengkap_insiden_k3_dilaporkan_ditinjau_lalu_ditutup(): void
    {
        $insiden = $this->laporkan();
        $ditinjau = $this->k3->review($insiden, $this->adminMutu->id);
        $ditutup = $this->k3->close($ditinjau, 'Pemasangan safety box tambahan di ruang tindakan');

        $this->assertSame(K3Incident::STATUS_DITUTUP, $ditutup->status);
        $this->assertNotNull($ditutup->closed_at);
        $this->assertSame('Pemasangan safety box tambahan di ruang tindakan', $ditutup->corrective_action);
    }

    #[Test]
    public function layar_ppi_dan_k3_hanya_untuk_admin_mutu(): void
    {
        $this->actingAs($this->adminMutu)->get(route('quality.ppi.index'))->assertOk();
        $this->actingAs($this->adminMutu)->get(route('quality.k3.index'))->assertOk();

        $dokter = User::query()->create([
            'username' => 'uji-dokter-ppi', 'name' => 'Dokter Uji', 'password' => 'password', 'is_active' => true,
        ]);
        $dokter->roles()->attach(Role::query()->where('code', 'dokter')->firstOrFail());

        $this->actingAs($dokter)->get(route('quality.k3.index'))->assertForbidden();
    }

    private function laporkan(): K3Incident
    {
        return $this->k3->report([
            'occurred_at' => now(),
            'location' => 'Ruang Tindakan Poli Umum',
            'body_part' => 'Jari telunjuk kanan',
            'injury_impact' => 'Luka tusuk ringan',
            'injury_type' => 'Tertusuk jarum bekas',
            'job_type' => 'Perawat',
            'cause' => 'Recapping jarum suntik',
            'description' => 'Perawat tertusuk jarum bekas saat proses recapping sebelum pembuangan.',
        ], $this->adminMutu->id);
    }
}
