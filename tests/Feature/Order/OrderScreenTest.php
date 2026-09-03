<?php

namespace Tests\Feature\Order;

use App\Modules\Catalog\Models\Payer;
use App\Modules\Encounter\Models\Registration;
use App\Modules\Encounter\Services\RegistrationService;
use App\Modules\Identity\Services\PatientRegistry;
use App\Modules\Order\Database\Seeders\TestCatalogSeeder;
use App\Modules\Order\Models\LabRadiologyOrder;
use App\Modules\Order\Models\TestCatalog;
use App\Modules\Order\Services\OrderService;
use App\Modules\Organization\Models\Unit;
use App\Modules\Platform\Database\Seeders\PermissionCatalogSeeder;
use App\Modules\Platform\Database\Seeders\RoleSeeder;
use App\Modules\Platform\Models\Role;
use App\Modules\Platform\Models\User;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class OrderScreenTest extends TestCase
{
    use RefreshDatabase;

    private User $petugasLab;
    private User $petugasRadiologi;
    private User $petugasLabPa;
    private OrderService $orders;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([
            PermissionCatalogSeeder::class,
            RoleSeeder::class,
            ReferenceDataSeeder::class,
            TestCatalogSeeder::class,
        ]);

        $this->orders = app(OrderService::class);

        $this->petugasLab = $this->buatPengguna('petugas-lab');
        $this->petugasRadiologi = $this->buatPengguna('petugas-radiologi');
        $this->petugasLabPa = $this->buatPengguna('petugas-lab-pa');
    }

    #[Test]
    public function petugas_lab_dapat_membuka_antrean_lab(): void
    {
        $this->actingAs($this->petugasLab)
            ->get(route('order.index', 'lab'))
            ->assertOk()
            ->assertSee('Laboratorium');
    }

    #[Test]
    public function petugas_lab_ditolak_mengakses_antrean_radiologi(): void
    {
        $this->actingAs($this->petugasLab)
            ->get(route('order.index', 'radiologi'))
            ->assertForbidden();
    }

    #[Test]
    public function petugas_radiologi_ditolak_mengakses_antrean_lab(): void
    {
        $this->actingAs($this->petugasRadiologi)
            ->get(route('order.index', 'lab'))
            ->assertForbidden();
    }

    #[Test]
    public function kategori_di_luar_lab_radiologi_pa_mengembalikan_404(): void
    {
        $this->actingAs($this->petugasLab)
            ->get(route('order.index', 'farmasi'))
            ->assertNotFound();
    }

    #[Test]
    public function petugas_lab_pa_dapat_membuka_antrean_pa(): void
    {
        $this->actingAs($this->petugasLabPa)
            ->get(route('order.index', 'pa'))
            ->assertOk()
            ->assertSee('Patologi Anatomi');
    }

    #[Test]
    public function petugas_lab_ditolak_mengakses_antrean_pa(): void
    {
        $this->actingAs($this->petugasLab)
            ->get(route('order.index', 'pa'))
            ->assertForbidden();
    }

    #[Test]
    public function petugas_lab_pa_ditolak_mengakses_antrean_lab(): void
    {
        $this->actingAs($this->petugasLabPa)
            ->get(route('order.index', 'lab'))
            ->assertForbidden();
    }

    #[Test]
    public function petugas_lab_dapat_membuka_order_dari_kunjungan(): void
    {
        $registrasi = $this->daftarkan();

        $this->actingAs($this->petugasLab)
            ->post(route('order.buat', ['lab', $registrasi->id]))
            ->assertRedirect();

        $this->assertDatabaseHas('orders.orders', [
            'registration_id' => $registrasi->id, 'category' => 'lab',
        ]);
    }

    #[Test]
    public function alur_lengkap_dari_permintaan_sampai_verifikasi_lewat_layar(): void
    {
        $registrasi = $this->daftarkan();
        $tes = TestCatalog::query()->where('code', 'LAB-GDS')->firstOrFail();

        $this->actingAs($this->petugasLab)->post(route('order.buat', ['lab', $registrasi->id]));
        $order = LabRadiologyOrder::query()->where('registration_id', $registrasi->id)->firstOrFail();

        $this->actingAs($this->petugasLab)
            ->post(route('order.item.simpan', ['lab', $order]), ['test_id' => $tes->id])
            ->assertRedirect();

        $this->actingAs($this->petugasLab)
            ->post(route('order.proses', ['lab', $order]))
            ->assertRedirect();
        $this->assertSame('diproses', $order->fresh()->status);

        $item = $order->fresh()->items()->firstOrFail();

        $this->actingAs($this->petugasLab)
            ->post(route('order.hasil.simpan', ['lab', $item]), ['result_numeric' => 95])
            ->assertRedirect();
        $this->assertSame('hasil-tersedia', $order->fresh()->status);

        $this->actingAs($this->petugasLab)
            ->post(route('order.verifikasi', ['lab', $order]))
            ->assertRedirect()
            ->assertSessionHas('sukses');

        $this->assertSame('selesai', $order->fresh()->status);
    }

    #[Test]
    public function layar_order_menampilkan_rincian_pemeriksaan(): void
    {
        $order = $this->orders->create($this->daftarkan()->id, 'lab');
        $this->orders->addItem($order, TestCatalog::query()->where('code', 'LAB-HB')->value('id'));

        $this->actingAs($this->petugasLab)
            ->get(route('order.show', ['lab', $order]))
            ->assertOk()
            ->assertSee('Hemoglobin')
            ->assertSee($order->order_number);
    }

    // ------------------------------------------------------------------ bantu

    private function buatPengguna(string $kodePeran): User
    {
        $user = User::query()->create([
            'username' => 'uji-order-' . $kodePeran,
            'name' => 'Pengguna ' . $kodePeran,
            'password' => 'password',
            'is_active' => true,
        ]);

        $user->roles()->attach(Role::query()->where('code', $kodePeran)->firstOrFail());

        return $user->fresh(['roles']);
    }

    private function daftarkan(): Registration
    {
        static $urut = 0;
        $urut++;

        $pasien = app(PatientRegistry::class)->register([
            'name' => 'Pasien Order Http ' . $urut, 'sex' => 'L', 'birth_date' => '1990-01-01',
        ]);

        return app(RegistrationService::class)->register(
            patientId: $pasien->id,
            unitId: Unit::query()->where('code', 'POL-UMUM')->value('id'),
            payerId: Payer::query()->where('code', 'UMUM')->value('id'),
        );
    }
}
