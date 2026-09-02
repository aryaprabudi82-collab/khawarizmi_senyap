<?php

namespace Tests\Feature\Finance;

use App\Modules\Billing\Services\InvoiceService;
use App\Modules\Catalog\Models\Payer;
use App\Modules\Encounter\Models\Registration;
use App\Modules\Encounter\Services\RegistrationService;
use App\Modules\Finance\Database\Seeders\ChartOfAccountsSeeder;
use App\Modules\Finance\Models\Account;
use App\Modules\Finance\Models\JournalEntry;
use App\Modules\Finance\Models\Receivable;
use App\Modules\Finance\Services\FinanceException;
use App\Modules\Finance\Services\PostingService;
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
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class PostingTest extends TestCase
{
    use RefreshDatabase;

    private PostingService $posting;
    private InvoiceService $invoices;
    private OrderService $orders;
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

        $this->posting = app(PostingService::class);
        $this->invoices = app(InvoiceService::class);
        $this->orders = app(OrderService::class);

        $this->petugasKeuangan = User::query()->create([
            'username' => 'uji-keuangan', 'name' => 'Petugas Keuangan Uji', 'password' => 'password', 'is_active' => true,
        ]);
        $this->petugasKeuangan->roles()->attach(Role::query()->where('code', 'petugas-keuangan')->firstOrFail());
    }

    #[Test]
    public function tagihan_pasien_lunas_memposting_jurnal_kas_terhadap_pendapatan(): void
    {
        $tagihan = $this->invoices->openInvoice($this->daftarkan('UMUM')->id);
        $this->invoices->pay($tagihan, 50000, 'tunai');

        $diposting = $this->posting->syncFromBilling();

        $this->assertSame(1, $diposting);

        $entry = JournalEntry::query()->where('reference_type', 'invoice')->where('reference_id', $tagihan->id)->firstOrFail();
        $baris = $entry->lines()->with('account')->get();

        $this->assertCount(2, $baris);
        $this->assertEqualsWithDelta(50000.0, (float) $baris->sum('debit'), 0.001);
        $this->assertEqualsWithDelta(50000.0, (float) $baris->sum('credit'), 0.001);
        $this->assertTrue($baris->contains(fn ($b) => $b->account->type === 'kas' && (float) $b->debit === 50000.0));
        $this->assertTrue($baris->contains(fn ($b) => $b->account->type === 'pendapatan' && (float) $b->credit === 50000.0));
    }

    #[Test]
    public function registrasi_bpjs_tanpa_biaya_tidak_menghasilkan_jurnal_atau_piutang(): void
    {
        // Tarif registrasi BPJS di data referensi memang Rp 0 - pasien tidak
        // ditagih untuk registrasi. Ini pernah menjatuhkan sistem: baris
        // jurnal debit=0/kredit=0 ditolak CHECK constraint. Sekarang tagihan
        // bernilai nol tidak menghasilkan baris jurnal maupun piutang sama
        // sekali, tapi tetap ditandai sudah diperiksa.
        $tagihan = $this->invoices->openInvoice($this->daftarkan('BPJS')->id);
        $this->assertSame('0.00', $tagihan->total_amount);

        $diposting = $this->posting->syncFromBilling();

        $this->assertSame(1, $diposting);

        $entry = JournalEntry::query()->where('reference_type', 'invoice')->where('reference_id', $tagihan->id)->firstOrFail();
        $this->assertSame(0, $entry->lines()->count());
        $this->assertSame(0, Receivable::query()->where('invoice_id', $tagihan->id)->count());

        // Tidak diproses ulang pada sinkronisasi berikutnya.
        $this->assertSame(0, $this->posting->syncFromBilling());
    }

    #[Test]
    public function tagihan_penjamin_bernilai_memposting_piutang_dan_membuka_receivable(): void
    {
        $tagihan = $this->tagihanBpjsBernilai();

        $diposting = $this->posting->syncFromBilling();
        $this->assertSame(1, $diposting);

        $entry = JournalEntry::query()->where('reference_type', 'invoice')->where('reference_id', $tagihan->id)->firstOrFail();
        $baris = $entry->lines()->with('account')->get();

        $this->assertTrue($baris->contains(fn ($b) => $b->account->type === 'piutang' && (float) $b->debit === 30000.0));
        $this->assertTrue($baris->contains(fn ($b) => $b->account->type === 'pendapatan' && (float) $b->credit === 30000.0));

        $piutang = Receivable::query()->where('invoice_id', $tagihan->id)->firstOrFail();
        $this->assertSame(Receivable::STATUS_TERBUKA, $piutang->status);
        $this->assertSame('30000.00', $piutang->amount);
    }

    #[Test]
    public function setiap_jurnal_selalu_seimbang_debit_dan_kredit(): void
    {
        $this->invoices->pay($this->invoices->openInvoice($this->daftarkan('UMUM')->id), 50000, 'tunai');
        $this->tagihanBpjsBernilai();
        $this->invoices->openInvoice($this->daftarkan('BPJS')->id); // bernilai nol, ikut diperiksa

        $this->posting->syncFromBilling();

        $diperiksa = 0;

        foreach (JournalEntry::query()->get() as $entry) {
            $baris = $entry->lines;
            $this->assertEqualsWithDelta(
                (float) $baris->sum('debit'),
                (float) $baris->sum('credit'),
                0.001,
                "Jurnal {$entry->entry_number} tidak seimbang."
            );
            $diperiksa++;
        }

        $this->assertSame(3, $diperiksa);
    }

    #[Test]
    public function sinkronisasi_ulang_tidak_memposting_tagihan_yang_sama_dua_kali(): void
    {
        $this->invoices->pay($this->invoices->openInvoice($this->daftarkan('UMUM')->id), 50000, 'tunai');

        $pertama = $this->posting->syncFromBilling();
        $kedua = $this->posting->syncFromBilling();

        $this->assertSame(1, $pertama);
        $this->assertSame(0, $kedua);
        $this->assertSame(1, JournalEntry::query()->where('reference_type', 'invoice')->count());
    }

    #[Test]
    public function tagihan_yang_masih_terbuka_belum_diposting(): void
    {
        $this->invoices->openInvoice($this->daftarkan('UMUM')->id);
        // Belum dibayar - masih 'terbuka', bukan 'lunas'.

        $diposting = $this->posting->syncFromBilling();

        $this->assertSame(0, $diposting);
        $this->assertSame(0, JournalEntry::query()->count());
    }

    #[Test]
    public function penerimaan_piutang_menutup_status_dan_memposting_jurnal_pembalik(): void
    {
        $tagihan = $this->tagihanBpjsBernilai();
        $this->posting->syncFromBilling();
        $piutang = Receivable::query()->where('invoice_id', $tagihan->id)->firstOrFail();

        $ditagih = $this->posting->collectReceivable($piutang, 'SP2D-000123', $this->petugasKeuangan);

        $this->assertSame(Receivable::STATUS_TERTAGIH, $ditagih->status);
        $this->assertSame('SP2D-000123', $ditagih->collection_reference);
        $this->assertSame($this->petugasKeuangan->id, $ditagih->collected_by);

        $entry = JournalEntry::query()->where('reference_type', 'penerimaan-piutang')->where('reference_id', $piutang->id)->firstOrFail();
        $baris = $entry->lines()->with('account')->get();

        $this->assertTrue($baris->contains(fn ($b) => $b->account->type === 'kas' && (float) $b->debit === 30000.0));
        $this->assertTrue($baris->contains(fn ($b) => $b->account->type === 'piutang' && (float) $b->credit === 30000.0));
    }

    #[Test]
    public function piutang_yang_sudah_tertagih_tidak_bisa_ditagih_lagi(): void
    {
        $tagihan = $this->tagihanBpjsBernilai();
        $this->posting->syncFromBilling();
        $piutang = Receivable::query()->where('invoice_id', $tagihan->id)->firstOrFail();
        $this->posting->collectReceivable($piutang, 'SP2D-001', $this->petugasKeuangan);

        $this->expectException(FinanceException::class);
        $this->expectExceptionMessage('sudah tertagih');

        $this->posting->collectReceivable($piutang->refresh(), 'SP2D-002', $this->petugasKeuangan);
    }

    #[Test]
    public function basis_data_menolak_baris_jurnal_yang_mengisi_debit_dan_kredit_sekaligus(): void
    {
        $tagihan = $this->invoices->openInvoice($this->daftarkan('UMUM')->id);
        $this->invoices->pay($tagihan, 50000, 'tunai');
        $this->posting->syncFromBilling();

        $entry = JournalEntry::query()->firstOrFail();
        $akun = Account::query()->first();

        $this->expectException(QueryException::class);

        DB::table('finance.journal_lines')->insert([
            'journal_entry_id' => $entry->id, 'account_id' => $akun->id, 'debit' => 100, 'credit' => 100,
        ]);
    }

    #[Test]
    public function basis_data_menolak_baris_jurnal_yang_tidak_mengisi_keduanya(): void
    {
        $tagihan = $this->invoices->openInvoice($this->daftarkan('UMUM')->id);
        $this->invoices->pay($tagihan, 50000, 'tunai');
        $this->posting->syncFromBilling();

        $entry = JournalEntry::query()->firstOrFail();
        $akun = Account::query()->first();

        $this->expectException(QueryException::class);

        DB::table('finance.journal_lines')->insert([
            'journal_entry_id' => $entry->id, 'account_id' => $akun->id, 'debit' => 0, 'credit' => 0,
        ]);
    }

    // ------------------------------------------------------------------ bantu

    private function daftarkan(string $kodePenjamin): Registration
    {
        static $urut = 0;
        $urut++;

        $pasien = app(PatientRegistry::class)->register([
            'name' => 'Pasien Finance ' . $urut, 'sex' => 'L', 'birth_date' => '1990-01-01',
        ]);

        return app(RegistrationService::class)->register(
            patientId: $pasien->id,
            unitId: Unit::query()->where('code', 'POL-UMUM')->value('id'),
            payerId: Payer::query()->where('code', $kodePenjamin)->value('id'),
        );
    }

    /** Kunjungan BPJS dengan satu pemeriksaan lab terverifikasi, supaya tagihannya bernilai. */
    private function tagihanBpjsBernilai(): \App\Modules\Billing\Models\Invoice
    {
        $registrasi = $this->daftarkan('BPJS');

        $order = $this->orders->create($registrasi->id, 'lab');
        $item = $this->orders->addItem($order, TestCatalog::query()->where('code', 'LAB-GDS')->value('id'));
        $this->orders->enterResult($item, numeric: 95);
        $this->orders->verify($order->refresh());

        return $this->invoices->openInvoice($registrasi->id);
    }
}
