<?php

namespace Tests\Feature\Billing;

use App\Modules\Billing\Models\Invoice;
use App\Modules\Billing\Models\PatientReceivable;
use App\Modules\Billing\Services\BillingException;
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
 * Domain I item B: piutang pasien.
 *
 * Konsep yang berbeda dari finance.receivables — itu piutang PENJAMIN
 * (klaim BPJS/asuransi, ditutup bukti transfer/SP2D), ini piutang PASIEN
 * (pulang belum lunas, uang muka, jatuh tempo, dicicil). Skema Khanza
 * piutang_pasien memastikannya: berkunci no_rawat, status Lunas/Belum
 * Lunas, uangmuka, sisapiutang, tgltempo.
 */
class PatientReceivableTest extends TestCase
{
    use RefreshDatabase;

    private InvoiceService $invoices;
    private RegistrationService $registrations;
    private User $kasir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([PermissionCatalogSeeder::class, RoleSeeder::class, ReferenceDataSeeder::class]);

        $this->invoices = app(InvoiceService::class);
        $this->registrations = app(RegistrationService::class);

        $this->kasir = User::query()->create([
            'username' => 'uji-kasir-piutang', 'name' => 'Kasir Piutang Uji', 'password' => 'password', 'is_active' => true,
        ]);
        $this->kasir->roles()->attach(Role::query()->where('code', 'kasir')->firstOrFail());
    }

    #[Test]
    public function sisa_tagihan_jadi_piutang_dengan_jatuh_tempo(): void
    {
        $tagihan = $this->tagihanBerbiaya(500_000);

        $piutang = $this->invoices->createReceivable($tagihan, now()->addDays(30), $this->kasir);

        $this->assertSame(500_000.0, (float) $piutang->principal_amount);
        $this->assertSame(500_000.0, $piutang->outstanding());
        $this->assertFalse($piutang->isSettled());
        $this->assertSame($tagihan->care_type, $piutang->care_type);
    }

    #[Test]
    public function uang_muka_langsung_mengurangi_pokok_piutang(): void
    {
        $tagihan = $this->tagihanBerbiaya(500_000);

        $piutang = $this->invoices->createReceivable($tagihan, now()->addDays(30), $this->kasir, downPayment: 200_000);

        $this->assertSame(300_000.0, (float) $piutang->principal_amount, 'Pokok = sisa SETELAH uang muka');
        $this->assertSame(300_000.0, $piutang->outstanding());
    }

    #[Test]
    public function uang_muka_tercatat_sebagai_pembayaran_biasa_bukan_jalur_terpisah(): void
    {
        $tagihan = $this->tagihanBerbiaya(500_000);

        $this->invoices->createReceivable($tagihan, now()->addDays(30), $this->kasir, downPayment: 200_000, downPaymentMethod: 'qris');

        $pembayaran = $tagihan->refresh()->payments()->get();

        $this->assertCount(1, $pembayaran);
        $this->assertSame('qris', $pembayaran->first()->method, 'Uang muka tidak dipaksa tunai');
        $this->assertSame(200_000.0, (float) $tagihan->paid_amount);
    }

    #[Test]
    public function cicilan_mengurangi_sisa_dan_melunasi_piutang(): void
    {
        $tagihan = $this->tagihanBerbiaya(600_000);
        $piutang = $this->invoices->createReceivable($tagihan, now()->addDays(30), $this->kasir);

        $this->invoices->pay($tagihan->refresh(), 250_000, 'tunai', $this->kasir);
        $this->assertSame(350_000.0, $piutang->refresh()->outstanding());
        $this->assertFalse($piutang->isSettled());

        $this->invoices->pay($tagihan->refresh(), 350_000, 'transfer', $this->kasir);

        $this->assertSame(0.0, $piutang->refresh()->outstanding());
        $this->assertTrue($piutang->isSettled(), 'Piutang lunas begitu tagihannya lunas — tidak ada status kedua');
        $this->assertSame(Invoice::STATUS_LUNAS, $tagihan->refresh()->status);
    }

    #[Test]
    public function sisa_piutang_tidak_disimpan_terpisah_jadi_tidak_bisa_berselisih(): void
    {
        $tagihan = $this->tagihanBerbiaya(400_000);
        $piutang = $this->invoices->createReceivable($tagihan, now()->addDays(14), $this->kasir);

        $this->invoices->pay($tagihan->refresh(), 100_000, 'tunai', $this->kasir);

        $this->assertSame(
            $tagihan->refresh()->outstanding(),
            $piutang->refresh()->outstanding(),
            'Sisa piutang selalu sama dengan sisa tagihan karena memang angka yang sama'
        );
    }

    #[Test]
    public function piutang_lewat_jatuh_tempo_terdeteksi_terlambat(): void
    {
        $tagihan = $this->tagihanBerbiaya(300_000);
        $piutang = $this->invoices->createReceivable($tagihan, now()->addDays(7), $this->kasir);

        $this->assertFalse($piutang->isOverdue());

        $this->travelTo(now()->addDays(8));
        $this->assertTrue($piutang->refresh()->isOverdue());

        // Sudah lunas tidak boleh dihitung terlambat meski tanggalnya lewat.
        $this->invoices->pay($tagihan->refresh(), 300_000, 'tunai', $this->kasir);
        $this->assertFalse($piutang->refresh()->isOverdue());
    }

    #[Test]
    public function tagihan_penjamin_tidak_boleh_dijadikan_piutang_pasien(): void
    {
        $tagihan = $this->tagihanBerbiaya(500_000, penjamin: 'BPJS');

        $this->expectException(BillingException::class);
        $this->expectExceptionMessage('ditanggung penjamin');

        $this->invoices->createReceivable($tagihan, now()->addDays(30), $this->kasir);
    }

    #[Test]
    public function satu_tagihan_hanya_boleh_punya_satu_piutang_berlaku(): void
    {
        $tagihan = $this->tagihanBerbiaya(500_000);
        $this->invoices->createReceivable($tagihan, now()->addDays(30), $this->kasir);

        $this->expectException(BillingException::class);
        $this->expectExceptionMessage('sudah punya piutang');

        $this->invoices->createReceivable($tagihan->refresh(), now()->addDays(60), $this->kasir);
    }

    #[Test]
    public function tagihan_lunas_tidak_menyisakan_apa_pun_untuk_dijadikan_piutang(): void
    {
        $tagihan = $this->tagihanBerbiaya(300_000);

        $this->expectException(BillingException::class);
        $this->expectExceptionMessage('sudah lunas');

        $this->invoices->createReceivable($tagihan, now()->addDays(30), $this->kasir, downPayment: 300_000);
    }

    #[Test]
    public function jatuh_tempo_yang_sudah_lewat_ditolak(): void
    {
        $tagihan = $this->tagihanBerbiaya(300_000);

        $this->expectException(BillingException::class);
        $this->expectExceptionMessage('sudah lewat');

        $this->invoices->createReceivable($tagihan, now()->subDay(), $this->kasir);
    }

    #[Test]
    public function membatalkan_piutang_tidak_ikut_membatalkan_uang_yang_sudah_diterima(): void
    {
        $tagihan = $this->tagihanBerbiaya(500_000);
        $piutang = $this->invoices->createReceivable($tagihan, now()->addDays(30), $this->kasir, downPayment: 150_000);

        $this->invoices->cancelReceivable($piutang, 'Pasien akhirnya melunasi di tempat', $this->kasir);

        $this->assertTrue($piutang->refresh()->isCancelled());
        $this->assertSame(150_000.0, (float) $tagihan->refresh()->paid_amount, 'Uang yang sudah diterima tetap tercatat');
        $this->assertCount(1, $tagihan->payments()->whereNull('voided_at')->get());
    }

    #[Test]
    public function piutang_yang_dibatalkan_membebaskan_tagihan_untuk_piutang_baru(): void
    {
        $tagihan = $this->tagihanBerbiaya(500_000);
        $piutang = $this->invoices->createReceivable($tagihan, now()->addDays(30), $this->kasir);
        $this->invoices->cancelReceivable($piutang, 'Salah tanggal jatuh tempo', $this->kasir);

        $baru = $this->invoices->createReceivable($tagihan->refresh(), now()->addDays(45), $this->kasir);

        $this->assertNotSame($piutang->id, $baru->id);
        $this->assertSame(2, PatientReceivable::query()->where('invoice_id', $tagihan->id)->count());
    }

    #[Test]
    public function layar_piutang_pasien_hanya_untuk_yang_berhak(): void
    {
        $this->actingAs($this->kasir)->get(route('piutang-pasien.index'))->assertOk();

        $dokter = User::query()->create([
            'username' => 'uji-dokter-piutang', 'name' => 'Dokter Uji', 'password' => 'password', 'is_active' => true,
        ]);
        $dokter->roles()->attach(Role::query()->where('code', 'dokter')->firstOrFail());

        $this->actingAs($dokter)->get(route('piutang-pasien.index'))->assertForbidden();
    }

    #[Test]
    public function piutang_bisa_dicatat_lewat_http(): void
    {
        $tagihan = $this->tagihanBerbiaya(500_000);

        $this->actingAs($this->kasir)->post(route('piutang-pasien.simpan', $tagihan), [
            'due_date' => now()->addDays(30)->toDateString(),
            'down_payment' => 100_000,
            'down_payment_method' => 'debit',
        ])->assertRedirect()->assertSessionHas('sukses');

        $this->assertDatabaseHas('billing.patient_receivables', [
            'invoice_id' => $tagihan->id, 'principal_amount' => 400_000,
        ]);
    }

    #[Test]
    public function galat_piutang_jadi_pesan_di_layar_bukan_error_500(): void
    {
        $tagihan = $this->tagihanBerbiaya(500_000, penjamin: 'BPJS');

        $this->actingAs($this->kasir)->post(route('piutang-pasien.simpan', $tagihan), [
            'due_date' => now()->addDays(30)->toDateString(),
        ])->assertRedirect()->assertSessionHas('galat');

        $this->assertDatabaseCount('billing.patient_receivables', 0);
    }

    // ------------------------------------------------------------------ bantu

    /** Tagihan dengan satu baris biaya bernilai tertentu, supaya nominalnya bisa dipastikan. */
    private function tagihanBerbiaya(float $nilai, string $penjamin = 'UMUM'): Invoice
    {
        $registrasi = $this->daftarkan($penjamin);
        $tagihan = $this->invoices->openInvoice($registrasi->id);

        // Tagihan sudah berisi biaya registrasi dari sinkronisasi; menghapusnya
        // percuma karena sinkronisasi berikutnya mengembalikannya. Jadi selisihnya
        // saja yang ditambahkan, supaya total mendarat persis di $nilai.
        $selisih = $nilai - (float) $tagihan->total_amount;

        if ($selisih > 0) {
            $this->invoices->addAdjustment(
                $tagihan,
                \App\Modules\Billing\Models\ManualAdjustment::KIND_TAMBAHAN,
                'Biaya uji',
                $selisih,
                $this->kasir
            );
        }

        $tagihan->refresh();

        $this->assertSame($nilai, (float) $tagihan->total_amount, 'Fixture harus menghasilkan total yang persis');

        return $tagihan;
    }

    private function daftarkan(string $penjamin): Registration
    {
        static $urut = 0;
        $urut++;

        $pasien = app(PatientRegistry::class)->register([
            'name' => 'Pasien Piutang ' . $urut, 'sex' => 'L', 'birth_date' => '1990-01-01',
        ]);

        return $this->registrations->register(
            patientId: $pasien->id,
            unitId: Unit::query()->where('code', 'POL-UMUM')->firstOrFail()->id,
            payerId: Payer::query()->where('code', $penjamin)->value('id'),
        );
    }
}
