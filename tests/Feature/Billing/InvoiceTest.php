<?php

namespace Tests\Feature\Billing;

use App\Modules\Billing\Models\Invoice;
use App\Modules\Billing\Services\BillingException;
use App\Modules\Billing\Services\InvoiceService;
use App\Modules\Catalog\Models\Payer;
use App\Modules\Clinical\Database\Seeders\DiagnosisCodeSeeder;
use App\Modules\Encounter\Models\Registration;
use App\Modules\Encounter\Services\RegistrationService;
use App\Modules\Identity\Services\PatientRegistry;
use App\Modules\Organization\Models\Unit;
use App\Modules\Pharmacy\Database\Seeders\PharmacySeeder;
use App\Modules\Pharmacy\Models\Drug;
use App\Modules\Pharmacy\Models\StockLocation;
use App\Modules\Pharmacy\Services\PrescriptionService;
use App\Modules\Platform\Database\Seeders\PermissionCatalogSeeder;
use App\Modules\Platform\Database\Seeders\RoleSeeder;
use App\Modules\Platform\Models\Role;
use App\Modules\Platform\Models\User;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class InvoiceTest extends TestCase
{
    use RefreshDatabase;

    private InvoiceService $invoices;
    private User $kasir;
    private StockLocation $depo;

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
        $this->depo = StockLocation::query()->where('code', 'DEPO-RJ')->firstOrFail();

        $this->kasir = User::query()->create([
            'username' => 'uji-kasir', 'name' => 'Kasir Uji', 'password' => 'password', 'is_active' => true,
        ]);
        $this->kasir->roles()->attach(Role::query()->where('code', 'kasir')->firstOrFail());
    }

    #[Test]
    public function tagihan_dibuka_dengan_biaya_registrasi_terhitung(): void
    {
        $registrasi = $this->daftarkan('Umum');

        $tagihan = $this->invoices->openInvoice($registrasi->id);

        $this->assertSame($registrasi->patient_id, $tagihan->patient_id);
        $this->assertSame('50000.00', $tagihan->total_amount);
        $this->assertSame(Invoice::RESPONSIBILITY_PASIEN, $tagihan->payment_responsibility);
        $this->assertSame(Invoice::STATUS_TERBUKA, $tagihan->status);
    }

    #[Test]
    public function membuka_tagihan_dua_kali_tidak_menggandakan_baris(): void
    {
        $registrasi = $this->daftarkan('Umum');

        $a = $this->invoices->openInvoice($registrasi->id);
        $b = $this->invoices->openInvoice($registrasi->id);

        $this->assertSame($a->id, $b->id);
        $this->assertSame(1, Invoice::query()->count());
        $this->assertSame(1, $a->fresh()->chargeLines()->count());
    }

    #[Test]
    public function tagihan_penjamin_bpjs_langsung_tertutup_tanpa_menunggu_kasir(): void
    {
        $registrasi = $this->daftarkan('BPJS');

        $tagihan = $this->invoices->openInvoice($registrasi->id);

        $this->assertSame(Invoice::STATUS_DITANGGUNG_PENJAMIN, $tagihan->status);
        $this->assertSame(Invoice::RESPONSIBILITY_PENJAMIN, $tagihan->payment_responsibility);
        $this->assertFalse($tagihan->isPatientPayable());
        $this->assertNotNull($tagihan->closed_at);
    }

    #[Test]
    public function kasir_ditolak_menagih_tagihan_yang_ditanggung_penjamin(): void
    {
        $registrasi = $this->daftarkan('BPJS');
        $tagihan = $this->invoices->openInvoice($registrasi->id);

        $this->expectException(BillingException::class);
        $this->expectExceptionMessage('ditanggung penjamin');

        $this->invoices->pay($tagihan, 10000, 'tunai', $this->kasir);
    }

    #[Test]
    public function obat_yang_diserahkan_ikut_tertarik_sebagai_charge_line(): void
    {
        $registrasi = $this->daftarkan('Umum');
        $this->serahkanObat($registrasi, 'OBT-002', 10); // 10 x 500 = 5000

        $tagihan = $this->invoices->openInvoice($registrasi->id);

        $this->assertSame('55000.00', $tagihan->total_amount); // 50000 registrasi + 5000 obat
        $this->assertSame(2, $tagihan->chargeLines()->count());
    }

    #[Test]
    public function sinkronisasi_ulang_tidak_menggandakan_charge_line_obat(): void
    {
        $registrasi = $this->daftarkan('Umum');
        $this->serahkanObat($registrasi, 'OBT-002', 10);

        $tagihan = $this->invoices->openInvoice($registrasi->id);
        $this->invoices->syncCharges($tagihan);
        $this->invoices->syncCharges($tagihan->refresh());

        $this->assertSame(2, $tagihan->refresh()->chargeLines()->count());
        $this->assertSame('55000.00', $tagihan->total_amount);
    }

    #[Test]
    public function obat_yang_diserahkan_setelah_tagihan_dibuka_ikut_masuk_saat_disegarkan(): void
    {
        $registrasi = $this->daftarkan('Umum');
        $tagihan = $this->invoices->openInvoice($registrasi->id);
        $this->assertSame('50000.00', $tagihan->total_amount);

        $this->serahkanObat($registrasi, 'OBT-003', 5); // 5 x 1200 = 6000
        $this->invoices->syncCharges($tagihan->refresh());

        $this->assertSame('56000.00', $tagihan->refresh()->total_amount);
    }

    #[Test]
    public function pembayaran_penuh_melunasi_tagihan(): void
    {
        $tagihan = $this->invoices->openInvoice($this->daftarkan('Umum')->id);

        $this->invoices->pay($tagihan, 50000, 'tunai', $this->kasir);

        $tagihan->refresh();
        $this->assertSame(Invoice::STATUS_LUNAS, $tagihan->status);
        $this->assertSame(0.0, $tagihan->outstanding());
        $this->assertNotNull($tagihan->closed_at);
    }

    #[Test]
    public function pembayaran_sebagian_tidak_melunasi_tagihan(): void
    {
        $tagihan = $this->invoices->openInvoice($this->daftarkan('Umum')->id);

        $this->invoices->pay($tagihan, 30000, 'tunai', $this->kasir);

        $tagihan->refresh();
        $this->assertSame(Invoice::STATUS_TERBUKA, $tagihan->status);
        $this->assertSame(20000.0, $tagihan->outstanding());
    }

    #[Test]
    public function pembayaran_melebihi_sisa_tagihan_ditolak(): void
    {
        $tagihan = $this->invoices->openInvoice($this->daftarkan('Umum')->id);

        $this->expectException(BillingException::class);
        $this->expectExceptionMessage('melebihi sisa tagihan');

        $this->invoices->pay($tagihan, 999999, 'tunai', $this->kasir);
    }

    #[Test]
    public function basis_data_menolak_paid_amount_melebihi_total_walau_kode_lolos(): void
    {
        $tagihan = $this->invoices->openInvoice($this->daftarkan('Umum')->id);

        $this->expectException(\Illuminate\Database\QueryException::class);

        \Illuminate\Support\Facades\DB::statement(
            'UPDATE billing.invoices SET paid_amount = total_amount + 1 WHERE id = ?',
            [$tagihan->id]
        );
    }

    #[Test]
    public function pembatalan_pembayaran_mengembalikan_tagihan_menjadi_terbuka(): void
    {
        $tagihan = $this->invoices->openInvoice($this->daftarkan('Umum')->id);
        $pembayaran = $this->invoices->pay($tagihan, 50000, 'tunai', $this->kasir);

        $this->assertSame(Invoice::STATUS_LUNAS, $tagihan->refresh()->status);

        $this->invoices->voidPayment($pembayaran, 'Salah input jumlah', $this->kasir);

        $tagihan->refresh();
        $this->assertSame(Invoice::STATUS_TERBUKA, $tagihan->status);
        $this->assertSame(50000.0, $tagihan->outstanding());
        $this->assertNull($tagihan->closed_at);
    }

    #[Test]
    public function pembayaran_yang_sudah_dibatalkan_tidak_bisa_dibatalkan_lagi(): void
    {
        $tagihan = $this->invoices->openInvoice($this->daftarkan('Umum')->id);
        $pembayaran = $this->invoices->pay($tagihan, 50000, 'tunai', $this->kasir);
        $this->invoices->voidPayment($pembayaran, 'Salah input', $this->kasir);

        $this->expectException(BillingException::class);
        $this->expectExceptionMessage('sudah dibatalkan');

        $this->invoices->voidPayment($pembayaran->refresh(), 'Coba lagi', $this->kasir);
    }

    #[Test]
    public function tagihan_yang_sudah_dibayar_tidak_bisa_dibatalkan(): void
    {
        $tagihan = $this->invoices->openInvoice($this->daftarkan('Umum')->id);
        $this->invoices->pay($tagihan, 20000, 'tunai', $this->kasir);

        $this->expectException(BillingException::class);
        $this->expectExceptionMessage('sudah menerima pembayaran');

        $this->invoices->voidInvoice($tagihan->refresh(), 'batal', $this->kasir);
    }

    #[Test]
    public function tagihan_tanpa_pembayaran_bisa_dibatalkan(): void
    {
        $tagihan = $this->invoices->openInvoice($this->daftarkan('Umum')->id);

        $dibatalkan = $this->invoices->voidInvoice($tagihan, 'Pasien batal berobat', $this->kasir);

        $this->assertSame(Invoice::STATUS_VOID, $dibatalkan->status);
        $this->assertTrue($dibatalkan->isVoid());
    }

    #[Test]
    public function tagihan_yang_dibatalkan_menolak_pembayaran(): void
    {
        $tagihan = $this->invoices->openInvoice($this->daftarkan('Umum')->id);
        $this->invoices->voidInvoice($tagihan, 'batal', $this->kasir);

        $this->expectException(BillingException::class);
        $this->expectExceptionMessage('sudah dibatalkan');

        $this->invoices->pay($tagihan->refresh(), 10000, 'tunai', $this->kasir);
    }

    #[Test]
    public function total_tidak_pernah_turun_di_bawah_yang_sudah_dibayar(): void
    {
        // Jaga-jaga terhadap balapan sinkronisasi: total selalu >= paid_amount,
        // ditegakkan CHECK constraint di basis data.
        $tagihan = $this->invoices->openInvoice($this->daftarkan('Umum')->id);
        $this->invoices->pay($tagihan, 50000, 'tunai', $this->kasir);

        $this->invoices->syncCharges($tagihan->refresh());

        $this->assertSame('50000.00', $tagihan->refresh()->total_amount);
    }

    // ------------------------------------------------------------------ bantu

    private function daftarkan(string $kodePenjaminSingkat): Registration
    {
        static $urut = 0;
        $urut++;

        $kode = $kodePenjaminSingkat === 'Umum' ? 'UMUM' : 'BPJS';

        $pasien = app(PatientRegistry::class)->register([
            'name' => 'Pasien Billing ' . $urut, 'sex' => 'L', 'birth_date' => '1990-01-01',
        ]);

        return app(RegistrationService::class)->register(
            patientId: $pasien->id,
            unitId: Unit::query()->where('code', 'POL-UMUM')->value('id'),
            payerId: Payer::query()->where('code', $kode)->value('id'),
        );
    }

    private function serahkanObat(Registration $registrasi, string $kodeObat, float $jumlah): void
    {
        $resep = app(PrescriptionService::class)->create($registrasi->id);
        app(PrescriptionService::class)->addItem(
            $resep, Drug::query()->where('code', $kodeObat)->value('id'), $jumlah, '3x1'
        );
        app(PrescriptionService::class)->submit($resep->refresh());
        app(PrescriptionService::class)->review($resep->refresh(), 'disetujui', null, $this->kasir);
        app(PrescriptionService::class)->dispense($resep->refresh(), $this->depo->id, $this->kasir);
    }
}
