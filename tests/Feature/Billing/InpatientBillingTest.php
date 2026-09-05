<?php

namespace Tests\Feature\Billing;

use App\Modules\Billing\Models\Invoice;
use App\Modules\Billing\Models\ManualAdjustment;
use App\Modules\Billing\Services\BillingException;
use App\Modules\Billing\Services\InvoiceService;
use App\Modules\Catalog\Models\Payer;
use App\Modules\Encounter\Models\Registration;
use App\Modules\Encounter\Services\RegistrationService;
use App\Modules\Identity\Services\PatientRegistry;
use App\Modules\Inpatient\Models\Bed;
use App\Modules\Inpatient\Models\Room;
use App\Modules\Inpatient\Services\AdmissionService;
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
 * Domain I item A: pembayaran_ranap, tambahan_biaya, potongan_biaya.
 *
 * Celah sesungguhnya di balik pembayaran_ranap bukan "tagihan rawat inap
 * belum ada" — admisi sudah punya registration_id dan billing.invoices
 * memang berkunci pada registrasi — melainkan biaya kamarnya yang tidak
 * pernah sampai ke tagihan: InvoiceService punya lima sumber biaya dan
 * tidak satu pun kamar.
 */
class InpatientBillingTest extends TestCase
{
    use RefreshDatabase;

    private InvoiceService $invoices;
    private RegistrationService $registrations;
    private AdmissionService $admissions;
    private User $kasir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([PermissionCatalogSeeder::class, RoleSeeder::class, ReferenceDataSeeder::class]);

        $this->invoices = app(InvoiceService::class);
        $this->registrations = app(RegistrationService::class);
        $this->admissions = app(AdmissionService::class);

        $this->kasir = User::query()->create([
            'username' => 'uji-kasir-ranap', 'name' => 'Kasir Ranap Uji', 'password' => 'password', 'is_active' => true,
        ]);
        $this->kasir->roles()->attach(Role::query()->where('code', 'kasir')->firstOrFail());
    }

    #[Test]
    public function biaya_kamar_masuk_tagihan_satu_baris_per_hari_menginap(): void
    {
        $bed = $this->bedTersedia(300_000);
        $registrasi = $this->daftarkanRanap('Pasien Ranap Satu');

        $this->travelTo(now()->subDays(4)->setTime(9, 0));
        $this->admissions->admit($registrasi->id, $bed);

        $this->travelBack();
        $tagihan = $this->invoices->openInvoice($registrasi->id);

        $barisKamar = $tagihan->chargeLines()->where('source_type', 'kamar')->get();

        $this->assertCount(5, $barisKamar, 'Masuk 4 hari lalu dan masih dirawat: hari masuk s.d. hari ini');
        $this->assertSame(
            5 * 300_000.0,
            (float) $barisKamar->sum('amount'),
            'Tiap hari ditagih penuh tarif kamar'
        );
        $this->assertSame('ranap', $tagihan->care_type);
    }

    #[Test]
    public function sinkronisasi_ulang_tidak_menggandakan_hari_yang_sama(): void
    {
        $bed = $this->bedTersedia(200_000);
        $registrasi = $this->daftarkanRanap('Pasien Ranap Dua');

        $this->travelTo(now()->subDays(2)->setTime(8, 0));
        $this->admissions->admit($registrasi->id, $bed);

        $this->travelBack();
        $tagihan = $this->invoices->openInvoice($registrasi->id);
        $sebelum = $tagihan->chargeLines()->where('source_type', 'kamar')->count();

        $this->invoices->syncCharges($tagihan);
        $this->invoices->syncCharges($tagihan);

        $this->assertSame($sebelum, $tagihan->chargeLines()->where('source_type', 'kamar')->count());
    }

    #[Test]
    public function pasien_yang_pulang_ditagih_malam_yang_ditempati_bukan_hari_kalender(): void
    {
        $bed = $this->bedTersedia(150_000);
        $registrasi = $this->daftarkanRanap('Pasien Ranap Tiga');

        $this->travelTo(now()->subDays(3)->setTime(10, 0));
        $admisi = $this->admissions->admit($registrasi->id, $bed);

        // Pulang hari ini: menempati 3 malam, bukan 4 hari kalender.
        $this->travelBack();
        $admisi->update(['discharged_at' => now(), 'status' => 'pulang', 'discharge_status' => 'sembuh']);

        $tagihan = $this->invoices->openInvoice($registrasi->id);

        $this->assertCount(3, $tagihan->chargeLines()->where('source_type', 'kamar')->get());
    }

    #[Test]
    public function masuk_dan_pulang_di_hari_yang_sama_tetap_ditagih_satu_hari(): void
    {
        $bed = $this->bedTersedia(150_000);
        $registrasi = $this->daftarkanRanap('Pasien Ranap Empat');

        $admisi = $this->admissions->admit($registrasi->id, $bed);
        $admisi->update(['discharged_at' => now()->addHours(6), 'status' => 'pulang', 'discharge_status' => 'sembuh']);

        $tagihan = $this->invoices->openInvoice($registrasi->id);

        $this->assertCount(1, $tagihan->chargeLines()->where('source_type', 'kamar')->get());
    }

    #[Test]
    public function biaya_registrasi_ranap_tidak_berlabel_rawat_jalan(): void
    {
        $registrasi = $this->daftarkanRanap('Pasien Label');
        $this->admissions->admit($registrasi->id, $this->bedTersedia(100_000));

        $tagihan = $this->invoices->openInvoice($registrasi->id);
        $baris = $tagihan->chargeLines()->where('source_type', 'registrasi')->firstOrFail();

        $this->assertSame('Biaya Registrasi Rawat Inap', $baris->description);
    }

    #[Test]
    public function tagihan_rawat_jalan_tidak_kebagian_biaya_kamar(): void
    {
        $registrasi = $this->daftarkanRalan('Pasien Ralan Satu');

        $tagihan = $this->invoices->openInvoice($registrasi->id);

        $this->assertSame('ralan', $tagihan->care_type);
        $this->assertCount(0, $tagihan->chargeLines()->where('source_type', 'kamar')->get());
    }

    #[Test]
    public function tambahan_biaya_menaikkan_total_dan_potongan_menurunkannya(): void
    {
        $tagihan = $this->invoices->openInvoice($this->daftarkanRalan('Pasien Penyesuaian')->id);
        $awal = (float) $tagihan->total_amount;

        $this->invoices->addAdjustment($tagihan, ManualAdjustment::KIND_TAMBAHAN, 'Ambulans', 250_000, $this->kasir);
        $this->assertSame($awal + 250_000, (float) $tagihan->refresh()->total_amount);

        $this->invoices->addAdjustment($tagihan, ManualAdjustment::KIND_POTONGAN, 'Keringanan sosial', 100_000, $this->kasir);
        $this->assertSame($awal + 150_000, (float) $tagihan->refresh()->total_amount);
    }

    #[Test]
    public function potongan_disimpan_negatif_apa_pun_tanda_yang_diketik(): void
    {
        $tagihan = $this->invoices->openInvoice($this->daftarkanRalan('Pasien Tanda')->id);
        $this->invoices->addAdjustment($tagihan, ManualAdjustment::KIND_TAMBAHAN, 'Biaya lain', 500_000, $this->kasir);

        // Diketik positif, harus tetap tersimpan negatif.
        $potongan = $this->invoices->addAdjustment($tagihan, ManualAdjustment::KIND_POTONGAN, 'Diskon', 50_000, $this->kasir);

        $this->assertSame(-50_000.0, (float) $potongan->amount);
    }

    #[Test]
    public function potongan_melebihi_total_tagihan_ditolak(): void
    {
        $tagihan = $this->invoices->openInvoice($this->daftarkanRalan('Pasien Potongan Besar')->id);

        $this->expectException(BillingException::class);
        $this->expectExceptionMessage('melebihi total tagihan');

        $this->invoices->addAdjustment($tagihan, ManualAdjustment::KIND_POTONGAN, 'Gratis', 99_000_000, $this->kasir);
    }

    #[Test]
    public function penyesuaian_yang_dibatalkan_hilang_dari_rincian_dan_totalnya(): void
    {
        $tagihan = $this->invoices->openInvoice($this->daftarkanRalan('Pasien Batal')->id);
        $awal = (float) $tagihan->total_amount;

        $penyesuaian = $this->invoices->addAdjustment($tagihan, ManualAdjustment::KIND_TAMBAHAN, 'Salah input', 300_000, $this->kasir);
        $this->assertSame($awal + 300_000, (float) $tagihan->refresh()->total_amount);

        $this->invoices->voidAdjustment($penyesuaian, 'Salah ketik nominal', $this->kasir);

        $this->assertSame($awal, (float) $tagihan->refresh()->total_amount);
        $this->assertCount(0, $tagihan->chargeLines()->where('source_type', 'penyesuaian')->get(),
            'Baris tagihannya ikut dicabut, bukan sekadar ditandai batal');
    }

    #[Test]
    public function penyesuaian_tidak_bisa_dibatalkan_dua_kali(): void
    {
        $tagihan = $this->invoices->openInvoice($this->daftarkanRalan('Pasien Batal Dua')->id);
        $penyesuaian = $this->invoices->addAdjustment($tagihan, ManualAdjustment::KIND_TAMBAHAN, 'Uji', 10_000, $this->kasir);
        $this->invoices->voidAdjustment($penyesuaian, 'Alasan pertama', $this->kasir);

        $this->expectException(BillingException::class);

        $this->invoices->voidAdjustment($penyesuaian->refresh(), 'Alasan kedua', $this->kasir);
    }

    #[Test]
    public function kasir_ranap_tidak_bisa_membuka_tagihan_rawat_jalan(): void
    {
        $ralan = $this->invoices->openInvoice($this->daftarkanRalan('Pasien Batas Akses')->id);

        $kasirRanap = User::query()->create([
            'username' => 'uji-kasir-ranap-saja', 'name' => 'Kasir Ranap Saja', 'password' => 'password', 'is_active' => true,
        ]);
        $peran = Role::query()->create(['code' => 'kasir-ranap-uji', 'name' => 'Kasir Ranap Uji', 'is_system' => false]);
        $peran->permissions()->attach(
            \App\Modules\Platform\Models\Permission::query()->where('code', 'pembayaran_ranap')->firstOrFail()
        );
        $kasirRanap->roles()->attach($peran);

        $this->actingAs($kasirRanap)->get(route('tagihan.show', $ralan))->assertForbidden();
        $this->actingAs($this->kasir)->get(route('tagihan.show', $ralan))->assertOk();
    }

    #[Test]
    public function pengguna_tanpa_kedua_permission_kasir_ditolak_dari_daftar(): void
    {
        $dokter = User::query()->create([
            'username' => 'uji-dokter-kasir', 'name' => 'Dokter Uji', 'password' => 'password', 'is_active' => true,
        ]);
        $dokter->roles()->attach(Role::query()->where('code', 'dokter')->firstOrFail());

        $this->actingAs($dokter)->get(route('tagihan.index'))->assertForbidden();
        $this->actingAs($this->kasir)->get(route('tagihan.index'))->assertOk();
    }

    #[Test]
    public function penyesuaian_bisa_disubmit_lewat_http(): void
    {
        $tagihan = $this->invoices->openInvoice($this->daftarkanRalan('Pasien HTTP')->id);

        $this->actingAs($this->kasir)->post(route('tagihan.penyesuaian.simpan', $tagihan), [
            'kind' => 'tambahan', 'description' => 'Ambulans', 'amount' => 150_000,
        ])->assertRedirect()->assertSessionHas('sukses');

        $this->assertDatabaseHas('billing.manual_adjustments', [
            'invoice_id' => $tagihan->id, 'kind' => 'tambahan', 'description' => 'Ambulans',
        ]);
    }

    #[Test]
    public function galat_penyesuaian_jadi_pesan_di_layar_bukan_error_500(): void
    {
        $tagihan = $this->invoices->openInvoice($this->daftarkanRalan('Pasien HTTP Galat')->id);

        $this->actingAs($this->kasir)->post(route('tagihan.penyesuaian.simpan', $tagihan), [
            'kind' => 'potongan', 'description' => 'Potongan kelewat besar', 'amount' => 99_000_000,
        ])->assertRedirect()->assertSessionHas('galat');

        $this->assertDatabaseCount('billing.manual_adjustments', 0);
    }

    // ------------------------------------------------------------------ bantu

    private function bedTersedia(float $tarifHarian): Bed
    {
        static $urut = 0;
        $urut++;

        $kamar = Room::query()->create([
            'room_number' => 'UJI-' . $urut,
            'room_class' => 'kelas-1',
            'daily_rate' => $tarifHarian,
            'is_active' => true,
        ]);

        return Bed::query()->create([
            'room_id' => $kamar->id,
            'bed_number' => 'A',
            'status' => Bed::STATUS_TERSEDIA,
        ]);
    }

    private function daftarkanRanap(string $nama): Registration
    {
        return $this->daftarkan($nama, ['care_type' => 'ranap']);
    }

    private function daftarkanRalan(string $nama): Registration
    {
        return $this->daftarkan($nama, []);
    }

    private function daftarkan(string $nama, array $extra): Registration
    {
        $pasien = app(PatientRegistry::class)->register(['name' => $nama, 'sex' => 'L', 'birth_date' => '1990-01-01']);

        return $this->registrations->register(
            patientId: $pasien->id,
            unitId: Unit::query()->where('code', 'POL-UMUM')->firstOrFail()->id,
            payerId: Payer::query()->where('code', 'UMUM')->value('id'),
            extra: $extra,
        );
    }
}
