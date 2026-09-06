<?php

namespace Tests\Feature\Billing;

use App\Modules\Billing\Models\Invoice;
use App\Modules\Billing\Models\ManualAdjustment;
use App\Modules\Billing\Models\PatientReceivable;
use App\Modules\Billing\Models\ReceivableCollection;
use App\Modules\Billing\Services\BillingException;
use App\Modules\Billing\Services\InvoiceService;
use App\Modules\Billing\Services\ReceivableCollectionService;
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
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Penagihan & laporan piutang pasien (domain K item D).
 *
 * Yang paling perlu dikunci: layar ini mencatat UPAYA PENAGIHAN, bukan
 * uangnya. Sisa piutang tetap diturunkan dari sisa tagihannya, sehingga
 * membayar tagihan otomatis menutup piutangnya — tanpa ada angka kedua
 * yang bisa menyimpang.
 */
class ReceivableCollectionTest extends TestCase
{
    use RefreshDatabase;

    private InvoiceService $invoices;
    private ReceivableCollectionService $penagihan;
    private RegistrationService $registrations;
    private User $kasir;
    private User $penyelia;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([PermissionCatalogSeeder::class, RoleSeeder::class, ReferenceDataSeeder::class]);

        $this->invoices = app(InvoiceService::class);
        $this->penagihan = app(ReceivableCollectionService::class);
        $this->registrations = app(RegistrationService::class);

        $this->kasir = User::query()->create([
            'username' => 'uji-kasir-tagih', 'name' => 'Kasir', 'password' => 'password', 'is_active' => true,
        ]);
        $this->kasir->roles()->attach(Role::query()->where('code', 'kasir')->firstOrFail());

        $this->penyelia = User::query()->create([
            'username' => 'uji-keuangan-tagih', 'name' => 'Penyelia Keuangan', 'password' => 'password', 'is_active' => true,
        ]);
        $this->penyelia->roles()->attach(Role::query()->where('code', 'petugas-keuangan')->firstOrFail());
    }

    // ---------------------------------------------------- pencatatan penagihan

    #[Test]
    public function upaya_penagihan_tercatat_dengan_hasil_dan_kanalnya(): void
    {
        $p = $this->piutang(500_000);

        $catatan = $this->tagih($p, 'telepon', 'dijanjikan');

        $this->assertSame('telepon', $catatan->channel);
        $this->assertSame('dijanjikan', $catatan->outcome);
        $this->assertFalse($catatan->isValidated(), 'Belum diverifikasi penyelia');
    }

    #[Test]
    public function hasil_penagihan_di_luar_daftar_ditolak(): void
    {
        $p = $this->piutang(500_000);

        $this->expectException(BillingException::class);
        $this->expectExceptionMessage('Hasil penagihan tidak dikenal');

        $this->tagih($p, 'telepon', 'lupa-lupa-ingat');
    }

    #[Test]
    public function piutang_yang_dibatalkan_tidak_bisa_ditagih(): void
    {
        $p = $this->piutang(500_000);
        $this->invoices->cancelReceivable($p, 'salah input', $this->kasir);

        $this->expectException(BillingException::class);
        $this->expectExceptionMessage('dibatalkan');

        $this->tagih($p->refresh(), 'telepon', 'dijanjikan');
    }

    /**
     * Inti pemisahan tugas: catatan penagihan adalah dasar penghapusan
     * piutang, jadi penagihnya sendiri tidak boleh memverifikasinya.
     */
    #[Test]
    public function penagih_tidak_bisa_memverifikasi_catatannya_sendiri(): void
    {
        $p = $this->piutang(500_000);
        $catatan = $this->tagih($p, 'telepon', 'menolak');

        $this->expectException(BillingException::class);
        $this->expectExceptionMessage('penagihnya sendiri');

        $this->penagihan->validateContact($catatan, $this->kasir->id);
    }

    #[Test]
    public function penyelia_lain_bisa_memverifikasi(): void
    {
        $p = $this->piutang(500_000);
        $catatan = $this->tagih($p, 'telepon', 'menolak');

        $catatan = $this->penagihan->validateContact($catatan, $this->penyelia->id);

        $this->assertTrue($catatan->isValidated());
        $this->assertCount(0, $this->penagihan->pendingValidation());
    }

    #[Test]
    public function catatan_tidak_bisa_diverifikasi_dua_kali(): void
    {
        $p = $this->piutang(500_000);
        $catatan = $this->penagihan->validateContact($this->tagih($p, 'surat', 'menolak'), $this->penyelia->id);

        $this->expectException(BillingException::class);
        $this->expectExceptionMessage('sudah diverifikasi');

        $this->penagihan->validateContact($catatan, $this->penyelia->id);
    }

    // ------------------------------------------------ satu sumber kebenaran

    /**
     * Inti item ini: membayar TAGIHANNYA otomatis menutup piutangnya.
     * Tidak ada mekanisme pembayaran kedua yang bisa menyimpang.
     */
    #[Test]
    public function membayar_tagihan_otomatis_menutup_piutangnya(): void
    {
        $p = $this->piutang(500_000);

        $this->assertCount(1, $this->penagihan->outstanding());

        $this->invoices->pay($p->invoice, 500_000, 'tunai', $this->kasir);

        $this->assertCount(0, $this->penagihan->outstanding(),
            'Piutang tertutup lewat pembayaran tagihan, bukan lewat angka kedua');
    }

    #[Test]
    public function pembayaran_sebagian_menyisakan_selisihnya(): void
    {
        $p = $this->piutang(500_000);

        $this->invoices->pay($p->invoice, 200_000, 'tunai', $this->kasir);

        $sisa = $this->penagihan->outstanding()->first();

        $this->assertSame(300_000.0, (float) $sisa->sisa);
    }

    // ---------------------------------------------------------------- laporan

    /**
     * Piutang yang belum pernah ditagih dipisahkan: menumpuk karena tidak
     * ada yang menagih dan menumpuk karena pasien menolak adalah dua
     * masalah berbeda dengan penanganan berbeda.
     */
    #[Test]
    public function piutang_yang_belum_pernah_ditagih_dipisahkan(): void
    {
        $sudah = $this->piutang(500_000);
        $this->piutang(300_000);

        $this->tagih($sudah, 'telepon', 'dijanjikan');

        $belum = $this->penagihan->neverContacted();

        $this->assertCount(1, $belum);
        $this->assertSame(300_000.0, (float) $belum->first()->sisa);
    }

    #[Test]
    public function jumlah_upaya_penagihan_ikut_dilaporkan(): void
    {
        $p = $this->piutang(500_000);

        $this->tagih($p, 'telepon', 'tidak-terhubung');
        $this->tagih($p, 'surat', 'dijanjikan');

        $baris = $this->penagihan->outstanding()->first();

        $this->assertSame(2, (int) $baris->upaya_tagih, 'Dua upaya, bukan dua baris piutang');
        $this->assertCount(1, $this->penagihan->outstanding(), 'Piutang tidak tergandakan oleh riwayat penagihannya');
    }

    /**
     * Tagihan yang ditanggung penjamin TIDAK boleh jadi piutang pasien —
     * itu ditagihkan lewat klaim. Invarian ini yang membuat pemilahan
     * per cara bayar di layar ini hampir selalu berisi satu baris, dan
     * itu memang benar: piutang per cara bayar yang dimaksud Khanza
     * adalah piutang PENJAMIN, bukan piutang pasien.
     */
    #[Test]
    public function tagihan_penjamin_tidak_bisa_jadi_piutang_pasien(): void
    {
        $this->piutang(500_000, 'UMUM');

        $perPenjamin = $this->penagihan->byPayer()->pluck('sisa', 'payer_name');
        $this->assertSame(500_000.0, (float) $perPenjamin['Umum / Bayar Sendiri']);

        $this->expectException(BillingException::class);
        $this->expectExceptionMessage('ditagihkan lewat klaim');

        $this->piutang(300_000, 'BPJS');
    }

    #[Test]
    public function umur_piutang_memisahkan_yang_belum_jatuh_tempo(): void
    {
        $this->piutang(500_000, tempo: now()->addDays(20));

        // createReceivable menolak jatuh tempo yang sudah lewat — invarian
        // yang benar, jadi piutang tunggakan dibuat dengan tempo sah lalu
        // dimundurkan lewat query, bukan dengan melonggarkan aturannya.
        $telat = $this->piutang(300_000);
        DB::table('billing.patient_receivables')->where('id', $telat->id)
            ->update(['due_date' => now()->subDays(45)->toDateString()]);

        $umur = $this->penagihan->aging()->keyBy('kelompok');

        $this->assertSame(500_000.0, (float) $umur['belum jatuh tempo']->sisa);
        $this->assertSame(300_000.0, (float) $umur['31-60 hari']->sisa);
    }

    // ----------------------------------------------------------------- layar

    #[Test]
    public function layar_penagihan_hanya_untuk_yang_berhak(): void
    {
        $this->actingAs($this->penyelia)->get(route('penagihan-piutang.index'))->assertOk();

        $dokter = User::query()->create([
            'username' => 'uji-dokter-tagih', 'name' => 'Dokter', 'password' => 'password', 'is_active' => true,
        ]);
        $dokter->roles()->attach(Role::query()->where('code', 'dokter')->firstOrFail());

        $this->actingAs($dokter)->get(route('penagihan-piutang.index'))->assertForbidden();
    }

    #[Test]
    public function penagihan_bisa_dicatat_lewat_http(): void
    {
        $p = $this->piutang(500_000);

        $this->actingAs($this->penyelia)
            ->post(route('penagihan-piutang.simpan', $p->id), [
                'contacted_on' => now()->toDateString(),
                'channel' => 'telepon',
                'outcome' => 'dijanjikan',
                'promised_on' => now()->addDays(7)->toDateString(),
            ])
            ->assertRedirect();

        $this->assertDatabaseCount('billing.patient_receivable_collections', 1);
    }

    #[Test]
    public function layar_menyatakan_bahwa_uangnya_dicatat_di_tempat_lain(): void
    {
        $this->actingAs($this->penyelia)
            ->get(route('penagihan-piutang.index'))
            ->assertOk()
            ->assertSee('mencatat upaya penagihannya, bukan uangnya', false);
    }

    // ------------------------------------------------------------------ bantu

    private function piutang(float $nilai, string $penjamin = 'UMUM', $tempo = null): PatientReceivable
    {
        return $this->invoices->createReceivable(
            $this->tagihanBerbiaya($nilai, $penjamin),
            $tempo ?? now()->addDays(30),
            $this->kasir,
        );
    }

    private function tagih(PatientReceivable $p, string $kanal, string $hasil): ReceivableCollection
    {
        return $this->penagihan->contact($p, [
            'contacted_on' => now()->toDateString(),
            'channel' => $kanal,
            'outcome' => $hasil,
        ], $this->kasir->id, $this->kasir->name);
    }

    private function tagihanBerbiaya(float $nilai, string $penjamin = 'UMUM'): Invoice
    {
        $tagihan = $this->invoices->openInvoice($this->daftarkan($penjamin)->id);

        $selisih = $nilai - (float) $tagihan->total_amount;

        if ($selisih > 0) {
            $this->invoices->addAdjustment(
                $tagihan, ManualAdjustment::KIND_TAMBAHAN, 'Biaya uji', $selisih, $this->kasir
            );
        }

        return $tagihan->refresh();
    }

    private function daftarkan(string $penjamin): Registration
    {
        static $urut = 0;
        $urut++;

        $pasien = app(PatientRegistry::class)->register([
            'name' => 'Pasien Tagih ' . $urut, 'sex' => 'L', 'birth_date' => '1990-01-01',
        ]);

        return $this->registrations->register(
            patientId: $pasien->id,
            unitId: Unit::query()->where('code', 'POL-UMUM')->firstOrFail()->id,
            payerId: Payer::query()->where('code', $penjamin)->value('id'),
        );
    }
}
