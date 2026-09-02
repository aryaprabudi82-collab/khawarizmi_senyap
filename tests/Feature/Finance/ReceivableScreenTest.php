<?php

namespace Tests\Feature\Finance;

use App\Modules\Billing\Services\InvoiceService;
use App\Modules\Catalog\Models\Payer;
use App\Modules\Encounter\Services\RegistrationService;
use App\Modules\Finance\Database\Seeders\ChartOfAccountsSeeder;
use App\Modules\Finance\Models\Receivable;
use App\Modules\Identity\Services\PatientRegistry;
use App\Modules\Order\Database\Seeders\TestCatalogSeeder;
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

class ReceivableScreenTest extends TestCase
{
    use RefreshDatabase;

    private User $petugasKeuangan;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([
            PermissionCatalogSeeder::class,
            RoleSeeder::class,
            ReferenceDataSeeder::class,
            TestCatalogSeeder::class,
            ChartOfAccountsSeeder::class,
        ]);

        $this->petugasKeuangan = User::query()->create([
            'username' => 'uji-keuangan-http', 'name' => 'Petugas Keuangan Uji', 'password' => 'password', 'is_active' => true,
        ]);
        $this->petugasKeuangan->roles()->attach(Role::query()->where('code', 'petugas-keuangan')->firstOrFail());
    }

    #[Test]
    public function layar_piutang_menyegarkan_dari_billing_dan_menampilkan_hasilnya(): void
    {
        $this->buatTagihanBpjsBernilai();

        $this->actingAs($this->petugasKeuangan)
            ->get(route('piutang.index'))
            ->assertOk()
            ->assertSee('Piutang Penjamin')
            ->assertSee('BPJS Kesehatan');
    }

    #[Test]
    public function petugas_keuangan_dapat_mencatat_piutang_tertagih(): void
    {
        $this->buatTagihanBpjsBernilai();
        $this->get(route('piutang.index')); // memicu sinkronisasi terlebih dahulu via kunjungan lain di bawah

        $this->actingAs($this->petugasKeuangan)->get(route('piutang.index'));
        $piutang = Receivable::query()->firstOrFail();

        $this->actingAs($this->petugasKeuangan)
            ->post(route('piutang.tagih', $piutang), ['reference' => 'SP2D-999'])
            ->assertRedirect()
            ->assertSessionHas('sukses');

        $this->assertSame(Receivable::STATUS_TERTAGIH, $piutang->fresh()->status);
    }

    #[Test]
    public function petugas_lain_tidak_bisa_mengakses_layar_piutang(): void
    {
        $petugasDaftar = User::query()->create([
            'username' => 'uji-daftar-piutang', 'name' => 'Petugas Daftar', 'password' => 'password', 'is_active' => true,
        ]);
        $petugasDaftar->roles()->attach(Role::query()->where('code', 'petugas-daftar')->firstOrFail());

        $this->actingAs($petugasDaftar)
            ->get(route('piutang.index'))
            ->assertForbidden();
    }

    // ------------------------------------------------------------------ bantu

    private function buatTagihanBpjsBernilai(): void
    {
        $pasien = app(PatientRegistry::class)->register([
            'name' => 'Pasien Piutang', 'sex' => 'L', 'birth_date' => '1990-01-01',
        ]);

        $registrasi = app(RegistrationService::class)->register(
            patientId: $pasien->id,
            unitId: Unit::query()->where('code', 'POL-UMUM')->value('id'),
            payerId: Payer::query()->where('code', 'BPJS')->value('id'),
        );

        $orders = app(OrderService::class);
        $order = $orders->create($registrasi->id, 'lab');
        $item = $orders->addItem($order, TestCatalog::query()->where('code', 'LAB-GDS')->value('id'));
        $orders->enterResult($item, numeric: 95);
        $orders->verify($order->refresh());

        app(InvoiceService::class)->openInvoice($registrasi->id);
    }
}
