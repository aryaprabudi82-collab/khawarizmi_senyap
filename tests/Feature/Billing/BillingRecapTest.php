<?php

namespace Tests\Feature\Billing;

use App\Modules\Billing\Models\ManualAdjustment;
use App\Modules\Billing\Services\BillingRecapService;
use App\Modules\Billing\Services\InvoiceService;
use App\Modules\Catalog\Models\Payer;
use App\Modules\Encounter\Models\Registration;
use App\Modules\Encounter\Services\RegistrationService;
use App\Modules\Identity\Services\PatientRegistry;
use App\Modules\Organization\Models\Unit;
use App\Modules\Platform\Database\Seeders\PermissionCatalogSeeder;
use App\Modules\Platform\Database\Seeders\RoleSeeder;
use App\Modules\Platform\Models\Role;
use App\Modules\Platform\Models\User;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Domain I item D: rekap billing.
 *
 * Hampir seluruh kode laporannya ternyata pengelompokan dari dua tabel
 * yang sudah ada — charge_lines (yang ditagihkan) dan payments (yang
 * dibayar) — jadi dilayani dua layar berpenyaring, bukan belasan layar.
 */
class BillingRecapTest extends TestCase
{
    use RefreshDatabase;

    private InvoiceService $invoices;
    private BillingRecapService $rekap;
    private RegistrationService $registrations;
    private User $kasir;
    private User $manajemen;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([PermissionCatalogSeeder::class, RoleSeeder::class, ReferenceDataSeeder::class]);

        $this->invoices = app(InvoiceService::class);
        $this->rekap = app(BillingRecapService::class);
        $this->registrations = app(RegistrationService::class);

        $this->kasir = $this->pengguna('uji-kasir-rekap', 'kasir');
        $this->manajemen = $this->pengguna('uji-manajemen-rekap', 'manajemen');
    }

    #[Test]
    public function biaya_dikelompokkan_per_jenis_sumbernya(): void
    {
        $tagihan = $this->tagihan();
        $this->invoices->addAdjustment($tagihan, ManualAdjustment::KIND_TAMBAHAN, 'Ambulans', 200_000, $this->kasir);

        $hariIni = now()->toDateString();
        $rekap = $this->rekap->bySource($hariIni, $hariIni)->keyBy('source_type');

        $this->assertArrayHasKey('registrasi', $rekap->all(), 'rekap_biaya_registrasi');
        $this->assertSame(200_000.0, (float) $rekap['penyesuaian']->total);
    }

    #[Test]
    public function rekap_biaya_bisa_disaring_jenis_rawat_dan_jenis_biaya(): void
    {
        $this->tagihan();
        $this->tagihan(careType: 'ranap');

        $hariIni = now()->toDateString();

        $ralan = $this->rekap->bySource($hariIni, $hariIni, 'ralan')->keyBy('source_type');
        $ranap = $this->rekap->bySource($hariIni, $hariIni, 'ranap')->keyBy('source_type');

        $this->assertSame(1, (int) $ralan['registrasi']->jumlah_baris);
        $this->assertSame(1, (int) $ranap['registrasi']->jumlah_baris);

        $harian = $this->rekap->dailyBySource('registrasi', $hariIni, $hariIni, 'ralan');
        $this->assertSame(1, (int) $harian->first()->jumlah_baris);
    }

    #[Test]
    public function tagihan_yang_dibatalkan_tidak_ikut_terhitung(): void
    {
        $tagihan = $this->tagihan();
        $hariIni = now()->toDateString();

        $sebelum = (float) $this->rekap->bySource($hariIni, $hariIni)->firstWhere('source_type', 'registrasi')->total;
        $this->assertGreaterThan(0, $sebelum);

        $this->invoices->voidInvoice($tagihan, 'Salah buka tagihan', $this->kasir);

        $this->assertNull(
            $this->rekap->bySource($hariIni, $hariIni)->firstWhere('source_type', 'registrasi'),
            'Tagihan void tidak boleh menyumbang angka apa pun'
        );
    }

    #[Test]
    public function pembayaran_dikelompokkan_per_hari_unit_dan_petugas(): void
    {
        $tagihan = $this->tagihan();
        $this->invoices->addAdjustment($tagihan, ManualAdjustment::KIND_TAMBAHAN, 'Biaya uji', 300_000, $this->kasir);
        $this->invoices->pay($tagihan->refresh(), 100_000, 'tunai', $this->kasir);

        $hariIni = now()->toDateString();

        $this->assertSame(100_000.0, (float) $this->rekap->paymentsDaily($hariIni, $hariIni)->first()->total);
        $this->assertSame(100_000.0, (float) $this->rekap->paymentsByUnit($hariIni, $hariIni)->first()->total);

        // rekap_per_shift — per petugas penerima, bukan rentang jam.
        $perPetugas = $this->rekap->paymentsByReceiver($hariIni, $hariIni);
        $this->assertSame($this->kasir->name, $perPetugas->first()->received_by_name);
        $this->assertSame(100_000.0, (float) $perPetugas->first()->total);
    }

    #[Test]
    public function pembayaran_yang_dibatalkan_tidak_ikut_terhitung(): void
    {
        $tagihan = $this->tagihan();
        $this->invoices->addAdjustment($tagihan, ManualAdjustment::KIND_TAMBAHAN, 'Biaya uji', 300_000, $this->kasir);
        $pembayaran = $this->invoices->pay($tagihan->refresh(), 150_000, 'tunai', $this->kasir);

        $hariIni = now()->toDateString();
        $this->assertSame(150_000.0, (float) $this->rekap->paymentsDaily($hariIni, $hariIni)->first()->total);

        $this->invoices->voidPayment($pembayaran, 'Salah input nominal', $this->kasir);

        $this->assertCount(
            0,
            $this->rekap->paymentsDaily($hariIni, $hariIni),
            'Uangnya tidak pernah jadi milik rumah sakit, jadi tidak boleh muncul di rekap'
        );
    }

    #[Test]
    public function rincian_dan_rekap_per_pasien_bisa_disaring_jenis_biaya(): void
    {
        $tagihan = $this->tagihan();
        $this->invoices->addAdjustment($tagihan, ManualAdjustment::KIND_TAMBAHAN, 'Ambulans', 250_000, $this->kasir);

        $hariIni = now()->toDateString();

        $rincian = $this->rekap->detail($hariIni, $hariIni, 'penyesuaian');
        $this->assertCount(1, $rincian);
        $this->assertSame('Tambahan: Ambulans', $rincian->first()->description);

        $perPasien = $this->rekap->perPatient($hariIni, $hariIni, 'penyesuaian');
        $this->assertSame(250_000.0, (float) $perPasien->first()->total);
    }

    /**
     * Kartu "per unit" sempat mengabaikan penyaring jenis biaya sementara
     * kartu lain menghormatinya — pengguna yang menyaring "kamar" akan
     * membaca angka seluruh jenis biaya di kartu itu tanpa tahu.
     */
    #[Test]
    public function rekap_per_unit_menghormati_penyaring_jenis_biaya(): void
    {
        $tagihan = $this->tagihan();
        $this->invoices->addAdjustment($tagihan, ManualAdjustment::KIND_TAMBAHAN, 'Ambulans', 400_000, $this->kasir);

        $hariIni = now()->toDateString();

        $semua = (float) $this->rekap->byUnit($hariIni, $hariIni)->first()->total;
        $hanyaPenyesuaian = (float) $this->rekap->byUnit($hariIni, $hariIni, null, 'penyesuaian')->first()->total;

        $this->assertSame(400_000.0, $hanyaPenyesuaian);
        $this->assertGreaterThan($hanyaPenyesuaian, $semua, 'Tanpa penyaring ikut menghitung biaya registrasi');
    }

    #[Test]
    public function dua_layar_rekap_digerbangi_peran_yang_berbeda(): void
    {
        // Kasir memegang rekap pembayaran (tutup kas), bukan rekap biaya.
        $this->actingAs($this->kasir)->get(route('rekap.pembayaran'))->assertOk();
        $this->actingAs($this->kasir)->get(route('rekap.biaya'))->assertForbidden();

        // Manajemen sebaliknya.
        $this->actingAs($this->manajemen)->get(route('rekap.biaya'))->assertOk();
        $this->actingAs($this->manajemen)->get(route('rekap.pembayaran'))->assertForbidden();
    }

    // ------------------------------------------------------------------ bantu

    private function tagihan(string $careType = 'ralan')
    {
        return $this->invoices->openInvoice($this->daftarkan($careType)->id);
    }

    private function daftarkan(string $careType): Registration
    {
        static $urut = 0;
        $urut++;

        $pasien = app(PatientRegistry::class)->register([
            'name' => 'Pasien Rekap ' . $urut, 'sex' => 'L', 'birth_date' => '1990-01-01',
        ]);

        return $this->registrations->register(
            patientId: $pasien->id,
            unitId: Unit::query()->where('code', 'POL-UMUM')->firstOrFail()->id,
            payerId: Payer::query()->where('code', 'UMUM')->value('id'),
            extra: $careType === 'ranap' ? ['care_type' => 'ranap'] : [],
        );
    }

    private function pengguna(string $username, string $peran): User
    {
        $user = User::query()->create([
            'username' => $username, 'name' => ucfirst($peran) . ' Uji', 'password' => 'password', 'is_active' => true,
        ]);
        $user->roles()->attach(Role::query()->where('code', $peran)->firstOrFail());

        return $user;
    }
}
