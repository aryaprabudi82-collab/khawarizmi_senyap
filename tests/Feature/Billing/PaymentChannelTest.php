<?php

namespace Tests\Feature\Billing;

use App\Modules\Billing\Database\Seeders\PaymentChannelSeeder;
use App\Modules\Billing\Models\ChannelPayment;
use App\Modules\Billing\Models\Invoice;
use App\Modules\Billing\Models\ManualAdjustment;
use App\Modules\Billing\Models\Payment;
use App\Modules\Billing\Models\PaymentChannel;
use App\Modules\Billing\Services\BillingException;
use App\Modules\Billing\Services\InvoiceService;
use App\Modules\Billing\Services\PaymentChannelService;
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
 * Kanal pembayaran bank (domain K item G).
 *
 * Yang paling perlu dikunci: pembayaran kanal menjadi billing.payments
 * yang sama persis dengan pembayaran kasir — tidak ada salinan uang kedua
 * — dan rujukan bank yang sama tidak bisa masuk dua kali.
 */
class PaymentChannelTest extends TestCase
{
    use RefreshDatabase;

    private PaymentChannelService $kanal;
    private InvoiceService $invoices;
    private RegistrationService $registrations;
    private User $kasir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([
            PermissionCatalogSeeder::class, RoleSeeder::class,
            ReferenceDataSeeder::class, PaymentChannelSeeder::class,
        ]);

        $this->kanal = app(PaymentChannelService::class);
        $this->invoices = app(InvoiceService::class);
        $this->registrations = app(RegistrationService::class);

        $this->kasir = User::query()->create([
            'username' => 'uji-kasir-kanal', 'name' => 'Kasir', 'password' => 'password', 'is_active' => true,
        ]);
        $this->kasir->roles()->attach(Role::query()->where('code', 'petugas-keuangan')->firstOrFail());
    }

    // ------------------------------------------------------------------ inbox

    #[Test]
    public function pemberitahuan_tercatat_dan_menggantung_sampai_dicocokkan(): void
    {
        $m = $this->terima(500_000, 'REF-001');

        $this->assertSame(ChannelPayment::DITERIMA, $m->status);
        $this->assertNull($m->invoice_id);
        $this->assertCount(1, $this->kanal->unmatched());
    }

    /**
     * Berkas rekening koran lazim diunggah berulang. Tanpa penjagaan ini,
     * pembayaran yang sama tercatat dua kali dan tagihan pasien terlihat
     * lunas berlebih.
     */
    #[Test]
    public function rujukan_bank_yang_sama_tidak_bisa_masuk_dua_kali(): void
    {
        $this->terima(500_000, 'REF-001');

        $this->expectException(BillingException::class);
        $this->expectExceptionMessage('sudah pernah diterima');

        $this->terima(500_000, 'REF-001');
    }

    /** Rujukan yang pernah ditolak boleh dimasukkan ulang setelah diperbaiki. */
    #[Test]
    public function rujukan_yang_ditolak_boleh_dimasukkan_ulang(): void
    {
        $m = $this->terima(500_000, 'REF-001');
        $this->kanal->reject($m, 'salah ketik nilainya');

        $baru = $this->terima(450_000, 'REF-001');

        $this->assertSame(ChannelPayment::DITERIMA, $baru->status);
    }

    #[Test]
    public function kanal_nonaktif_tidak_menerima_pemberitahuan(): void
    {
        $k = $this->kanalVa();
        $k->update(['is_active' => false]);

        $this->expectException(BillingException::class);
        $this->expectExceptionMessage('tidak aktif');

        $this->kanal->receive($k->refresh(), [
            'reference_number' => 'REF-X', 'amount' => 100_000, 'paid_at' => now(),
        ]);
    }

    // -------------------------------------------------------- satu sumber uang

    /**
     * Inti item ini: pembayaran kanal menjadi billing.payments yang sama
     * dengan pembayaran kasir — bukan salinan uang kedua.
     */
    #[Test]
    public function pencocokan_mencatat_pembayaran_pada_tagihannya(): void
    {
        $tagihan = $this->tagihanBerbiaya(500_000);
        $m = $this->terima(500_000, 'REF-001');

        $m = $this->kanal->match($m, $tagihan, $this->kasir);

        $this->assertSame(ChannelPayment::TERCOCOK, $m->status);
        $this->assertNotNull($m->payment_id);

        $bayar = Payment::query()->findOrFail($m->payment_id);

        $this->assertSame('transfer', $bayar->method, 'Jenis kanal jadi metode; identitas kanalnya terbaca lewat channel_payments');
        $this->assertStringContainsString('VA-MANDIRI', $bayar->note, 'Kode kanal tetap tercatat pada catatannya');
        $this->assertSame('500000.00', $bayar->amount);
        $this->assertSame(0.0, $tagihan->refresh()->outstanding(), 'Tagihan lunas lewat kanal');
    }

    #[Test]
    public function pembayaran_melebihi_sisa_tagihan_ditolak(): void
    {
        $tagihan = $this->tagihanBerbiaya(300_000);
        $m = $this->terima(500_000, 'REF-001');

        $this->expectException(BillingException::class);
        $this->expectExceptionMessage('melebihi sisa tagihan');

        $this->kanal->match($m, $tagihan, $this->kasir);
    }

    #[Test]
    public function pemberitahuan_tercocok_tidak_bisa_dicocokkan_lagi(): void
    {
        $tagihan = $this->tagihanBerbiaya(500_000);
        $m = $this->kanal->match($this->terima(500_000, 'REF-001'), $tagihan, $this->kasir);

        $this->expectException(BillingException::class);
        $this->expectExceptionMessage('masih baru');

        $this->kanal->match($m, $this->tagihanBerbiaya(500_000), $this->kasir);
    }

    #[Test]
    public function pemberitahuan_tercocok_tidak_bisa_ditolak(): void
    {
        $tagihan = $this->tagihanBerbiaya(500_000);
        $m = $this->kanal->match($this->terima(500_000, 'REF-001'), $tagihan, $this->kasir);

        $this->expectException(BillingException::class);
        $this->expectExceptionMessage('sudah dicocokkan');

        $this->kanal->reject($m, 'berubah pikiran');
    }

    /** set_tarif_online: kanal bisa dibatasi hanya untuk jenis rawat tertentu. */
    #[Test]
    public function kanal_yang_dilarang_untuk_jenis_rawat_ditolak(): void
    {
        $k = $this->kanalVa();
        $k->update(['allows_ralan' => false]);

        $m = $this->kanal->receive($k->refresh(), [
            'reference_number' => 'REF-002', 'amount' => 500_000, 'paid_at' => now(),
        ]);

        $this->expectException(BillingException::class);
        $this->expectExceptionMessage('tidak diizinkan');

        $this->kanal->match($m, $this->tagihanBerbiaya(500_000), $this->kasir);
    }

    // ---------------------------------------------------------------- laporan

    #[Test]
    public function rekap_memisahkan_yang_tercocok_dari_yang_menggantung(): void
    {
        $tagihan = $this->tagihanBerbiaya(500_000);
        $this->kanal->match($this->terima(500_000, 'REF-001'), $tagihan, $this->kasir);
        $this->terima(300_000, 'REF-002');

        $rekap = $this->kanal->recapByChannel(now()->toDateString(), now()->toDateString())->firstWhere('code', 'VA-MANDIRI');

        $this->assertSame(2, (int) $rekap->pemberitahuan);
        $this->assertSame(1, (int) $rekap->tercocok);
        $this->assertSame(1, (int) $rekap->menggantung);
        $this->assertSame(500_000.0, (float) $rekap->nilai_tercocok);
        $this->assertSame(300_000.0, (float) $rekap->nilai_menggantung);
    }

    #[Test]
    public function kanal_baru_bisa_ditambah_tanpa_migrasi(): void
    {
        $sebelum = $this->kanal->channels()->count();

        $this->kanal->saveChannel([
            'code' => 'VA-BNI', 'name' => 'Virtual Account BNI',
            'kind' => 'virtual-account', 'bank_name' => 'Bank BNI',
        ]);

        $this->assertSame($sebelum + 1, $this->kanal->channels()->count(),
            'Bank baru cukup satu baris, bukan tabel dan migrasi baru');
    }

    #[Test]
    public function kode_kanal_ganda_ditolak(): void
    {
        $this->expectException(BillingException::class);
        $this->expectExceptionMessage('sudah ada');

        $this->kanal->saveChannel(['code' => 'VA-MANDIRI', 'name' => 'Duplikat', 'kind' => 'transfer']);
    }

    // ----------------------------------------------------------------- layar

    #[Test]
    public function layar_kanal_hanya_untuk_yang_berhak(): void
    {
        $this->actingAs($this->kasir)->get(route('kanal.index'))->assertOk();

        $dokter = User::query()->create([
            'username' => 'uji-dokter-kanal', 'name' => 'Dokter', 'password' => 'password', 'is_active' => true,
        ]);
        $dokter->roles()->attach(Role::query()->where('code', 'dokter')->firstOrFail());

        $this->actingAs($dokter)->get(route('kanal.index'))->assertForbidden();
    }

    #[Test]
    public function pemberitahuan_bisa_dicatat_dan_dicocokkan_lewat_http(): void
    {
        $tagihan = $this->tagihanBerbiaya(500_000);

        $this->actingAs($this->kasir)
            ->post(route('kanal.terima', $this->kanalVa()->id), [
                'reference_number' => 'REF-HTTP',
                'amount' => 500_000,
                'paid_at' => now()->format('Y-m-d H:i:s'),
            ])
            ->assertRedirect();

        $m = ChannelPayment::query()->firstOrFail();

        $this->actingAs($this->kasir)
            ->post(route('kanal.cocokkan', $m->id), ['invoice_id' => $tagihan->id])
            ->assertRedirect();

        $this->assertSame(ChannelPayment::TERCOCOK, $m->refresh()->status);
        $this->assertSame(0.0, $tagihan->refresh()->outstanding());
    }

    #[Test]
    public function layar_menyatakan_penghubung_bank_belum_ada(): void
    {
        $this->actingAs($this->kasir)
            ->get(route('kanal.index'))
            ->assertOk()
            ->assertSee('Penghubung otomatis ke bank belum ada', false)
            ->assertSee('Satu layar untuk semua bank', false);
    }

    // ------------------------------------------------------------------ bantu

    private function kanalVa(): PaymentChannel
    {
        return PaymentChannel::query()->where('code', 'VA-MANDIRI')->firstOrFail();
    }

    private function terima(float $nilai, string $rujukan): ChannelPayment
    {
        return $this->kanal->receive($this->kanalVa(), [
            'reference_number' => $rujukan,
            'amount' => $nilai,
            'paid_at' => now(),
        ], $this->kasir->id);
    }

    private function tagihanBerbiaya(float $nilai): Invoice
    {
        $tagihan = $this->invoices->openInvoice($this->daftarkan()->id);

        $selisih = $nilai - (float) $tagihan->total_amount;

        if ($selisih > 0) {
            $this->invoices->addAdjustment(
                $tagihan, ManualAdjustment::KIND_TAMBAHAN, 'Biaya uji', $selisih, $this->kasir
            );
        }

        return $tagihan->refresh();
    }

    private function daftarkan(): Registration
    {
        static $urut = 0;
        $urut++;

        $pasien = app(PatientRegistry::class)->register([
            'name' => 'Pasien Kanal ' . $urut, 'sex' => 'L', 'birth_date' => '1990-01-01',
        ]);

        return $this->registrations->register(
            patientId: $pasien->id,
            unitId: Unit::query()->where('code', 'POL-UMUM')->firstOrFail()->id,
            payerId: Payer::query()->where('code', 'UMUM')->value('id'),
        );
    }
}
