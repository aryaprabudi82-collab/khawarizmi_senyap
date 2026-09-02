<?php

namespace Tests\Feature\Billing;

use App\Modules\Billing\Models\Invoice;
use App\Modules\Billing\Services\InvoiceService;
use App\Modules\Catalog\Models\Payer;
use App\Modules\Clinical\Database\Seeders\DiagnosisCodeSeeder;
use App\Modules\Encounter\Models\Registration;
use App\Modules\Encounter\Services\RegistrationService;
use App\Modules\Identity\Services\PatientRegistry;
use App\Modules\Organization\Models\Unit;
use App\Modules\Pharmacy\Database\Seeders\PharmacySeeder;
use App\Modules\Platform\Database\Seeders\PermissionCatalogSeeder;
use App\Modules\Platform\Database\Seeders\RoleSeeder;
use App\Modules\Platform\Models\Role;
use App\Modules\Platform\Models\User;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class InvoiceScreenTest extends TestCase
{
    use RefreshDatabase;

    private User $kasir;
    private InvoiceService $invoices;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([
            PermissionCatalogSeeder::class,
            RoleSeeder::class,
            ReferenceDataSeeder::class,
            DiagnosisCodeSeeder::class,
            PharmacySeeder::class,
        ]);

        $this->invoices = app(InvoiceService::class);

        $this->kasir = User::query()->create([
            'username' => 'uji-kasir-http', 'name' => 'Kasir Uji', 'password' => 'password', 'is_active' => true,
        ]);
        $this->kasir->roles()->attach(Role::query()->where('code', 'kasir')->firstOrFail());
    }

    #[Test]
    public function kasir_dapat_membuka_tagihan_dari_kunjungan(): void
    {
        $registrasi = $this->daftarkan();

        $this->actingAs($this->kasir)
            ->post(route('tagihan.buka', $registrasi->id))
            ->assertRedirect();

        $this->assertDatabaseHas('billing.invoices', ['registration_id' => $registrasi->id]);
    }

    #[Test]
    public function petugas_pendaftaran_tidak_bisa_membuka_kasir(): void
    {
        $registrasi = $this->daftarkan();
        $petugas = $this->buatPengguna('petugas-daftar');

        $this->actingAs($petugas)
            ->get(route('tagihan.index'))
            ->assertForbidden();

        $this->actingAs($petugas)
            ->post(route('tagihan.buka', $registrasi->id))
            ->assertForbidden();
    }

    #[Test]
    public function kasir_dapat_mencatat_pembayaran_lewat_layar(): void
    {
        $tagihan = $this->invoices->openInvoice($this->daftarkan()->id);

        $this->actingAs($this->kasir)
            ->post(route('tagihan.bayar', $tagihan), ['amount' => 50000, 'method' => 'tunai'])
            ->assertRedirect()
            ->assertSessionHas('sukses');

        $this->assertSame(Invoice::STATUS_LUNAS, $tagihan->fresh()->status);
    }

    #[Test]
    public function layar_kasir_menampilkan_ringkasan_dan_rincian(): void
    {
        $tagihan = $this->invoices->openInvoice($this->daftarkan()->id);

        $this->actingAs($this->kasir)
            ->get(route('tagihan.index'))
            ->assertOk()
            ->assertSee('Kasir Rawat Jalan')
            ->assertSee('Belum lunas');

        $this->actingAs($this->kasir)
            ->get(route('tagihan.show', $tagihan))
            ->assertOk()
            ->assertSee($tagihan->invoice_number)
            ->assertSee('Biaya Registrasi Rawat Jalan');
    }

    #[Test]
    public function pembayaran_tidak_valid_dikembalikan_sebagai_galat_bukan_error_500(): void
    {
        $tagihan = $this->invoices->openInvoice($this->daftarkan()->id);

        $this->actingAs($this->kasir)
            ->post(route('tagihan.bayar', $tagihan), ['amount' => 999999, 'method' => 'tunai'])
            ->assertRedirect()
            ->assertSessionHas('galat');
    }

    // ------------------------------------------------------------------ bantu

    private function buatPengguna(string $kodePeran): User
    {
        $user = User::query()->create([
            'username' => 'uji-billing-http-' . $kodePeran,
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
            'name' => 'Pasien Kasir ' . $urut, 'sex' => 'L', 'birth_date' => '1990-01-01',
        ]);

        return app(RegistrationService::class)->register(
            patientId: $pasien->id,
            unitId: Unit::query()->where('code', 'POL-UMUM')->value('id'),
            payerId: Payer::query()->where('code', 'UMUM')->value('id'),
        );
    }
}
