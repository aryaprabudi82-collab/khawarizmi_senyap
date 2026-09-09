<?php

namespace Tests\Feature\Quality;

use App\Modules\Platform\Database\Seeders\PermissionCatalogSeeder;
use App\Modules\Platform\Database\Seeders\RoleSeeder;
use App\Modules\Platform\Models\Role;
use App\Modules\Platform\Models\User;
use App\Modules\Quality\Models\IcraActivityType;
use App\Modules\Quality\Models\IcraArea;
use App\Modules\Quality\Models\IcraAssessment;
use App\Modules\Quality\Models\IcraRiskGroup;
use App\Modules\Quality\Models\IncidentReport;
use App\Modules\Quality\Services\IcraService;
use App\Modules\Quality\Services\IncidentReportService;
use App\Modules\Quality\Services\QualityException;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class QualityTest extends TestCase
{
    use RefreshDatabase;

    private IncidentReportService $incidents;

    private IcraService $icra;

    private User $adminMutu;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([PermissionCatalogSeeder::class, RoleSeeder::class, ReferenceDataSeeder::class]);

        $this->incidents = app(IncidentReportService::class);
        $this->icra = app(IcraService::class);

        $this->adminMutu = User::query()->create([
            'username' => 'uji-admin-mutu', 'name' => 'Admin Mutu Uji', 'password' => 'password', 'is_active' => true,
        ]);
        $this->adminMutu->roles()->attach(Role::query()->where('code', 'admin-mutu')->firstOrFail());
    }

    #[Test]
    public function insiden_dilaporkan_mendapat_nomor_berformat_ikp_tahun_urut(): void
    {
        $laporan = $this->laporkan();

        $this->assertMatchesRegularExpression('/^IKP-\d{4}-\d{5}$/', $laporan->report_number);
        $this->assertSame(IncidentReport::STATUS_DILAPORKAN, $laporan->status);
    }

    #[Test]
    public function nomor_insiden_urut_tidak_kembar(): void
    {
        $satu = $this->laporkan();
        $dua = $this->laporkan();

        $this->assertNotSame($satu->report_number, $dua->report_number);
    }

    #[Test]
    public function insiden_tidak_bisa_ditutup_sebelum_ditinjau(): void
    {
        $laporan = $this->laporkan();

        $this->expectException(QualityException::class);
        $this->expectExceptionMessage('harus ditinjau lebih dulu');

        $this->incidents->close($laporan, 'Akar masalah', 'Tindakan korektif');
    }

    #[Test]
    public function insiden_yang_sudah_ditinjau_tidak_bisa_ditinjau_ulang(): void
    {
        $laporan = $this->incidents->review($this->laporkan(), $this->adminMutu->id);

        $this->expectException(QualityException::class);
        $this->expectExceptionMessage('sudah ditinjau');

        $this->incidents->review($laporan, $this->adminMutu->id);
    }

    #[Test]
    public function alur_lengkap_insiden_dilaporkan_ditinjau_lalu_ditutup(): void
    {
        $laporan = $this->laporkan();
        $ditinjau = $this->incidents->review($laporan, $this->adminMutu->id);
        $ditutup = $this->incidents->close($ditinjau, 'Lantai licin tanpa tanda peringatan', 'Pasang tanda peringatan dan SOP pengecekan lantai');

        $this->assertSame(IncidentReport::STATUS_DITUTUP, $ditutup->status);
        $this->assertNotNull($ditutup->closed_at);
        $this->assertSame('Lantai licin tanpa tanda peringatan', $ditutup->root_cause);
    }

    #[Test]
    public function kajian_icra_mendapat_nomor_berformat_icra_tahun_urut(): void
    {
        $kajian = $this->kaji();

        $this->assertMatchesRegularExpression('/^ICRA-\d{4}-\d{5}$/', $kajian->assessment_number);
        $this->assertSame(IcraAssessment::STATUS_AKTIF, $kajian->status);
    }

    #[Test]
    public function kajian_icra_yang_sudah_selesai_tidak_bisa_dibatalkan(): void
    {
        $kajian = $this->icra->complete($this->kaji());

        $this->expectException(QualityException::class);

        $this->icra->cancel($kajian);
    }

    #[Test]
    public function layar_mutu_hanya_untuk_admin_mutu(): void
    {
        $this->actingAs($this->adminMutu)->get(route('quality.insiden.index'))->assertOk();
        $this->actingAs($this->adminMutu)->get(route('quality.icra.index'))->assertOk();

        $dokter = User::query()->create([
            'username' => 'uji-dokter-mutu', 'name' => 'Dokter Uji', 'password' => 'password', 'is_active' => true,
        ]);
        $dokter->roles()->attach(Role::query()->where('code', 'dokter')->firstOrFail());

        $this->actingAs($dokter)->get(route('quality.insiden.index'))->assertForbidden();
    }

    // ------------------------------------------------------------------ bantu

    private function laporkan(): IncidentReport
    {
        return $this->incidents->report([
            'incident_type' => IncidentReport::TYPE_KNC,
            'severity_band' => 'hijau',
            'occurred_at' => now(),
            'description' => 'Pasien hampir menerima obat yang salah, tertahan sebelum diserahkan.',
        ], $this->adminMutu->id);
    }

    private function kaji(): IcraAssessment
    {
        /*
         * Sejak domain R item A, tipe aktivitas dan area WAJIB: kelas
         * pencegahan dihitung dari matriks, dan pengkajian yang tidak bisa
         * menentukan kelasnya bukan pengkajian ICRA. `risk_class` tidak
         * lagi dikirim — kalau dikirim pun ia diabaikan.
         */
        $area = IcraArea::query()->create([
            'code' => 'AREA-QT',
            'name' => 'Gedung B Lantai 2',
            'risk_group_id' => IcraRiskGroup::query()->where('code', '2')->value('id'),
            'is_active' => true,
        ]);

        return $this->icra->assess([
            'project_name' => 'Renovasi Poliklinik Anak',
            'project_type' => 'renovasi',
            'location' => 'Gedung B Lantai 2',
            'infection_risk_level' => 'sedang',
            'fire_risk_level' => 'rendah',
            'safety_risk_level' => 'sedang',
            'utility_risk_level' => 'rendah',
        ], $this->adminMutu->id,
            IcraActivityType::query()->where('code', 'B')->firstOrFail(),
            $area
        );
    }
}
