<?php

namespace Tests\Feature\Finance;

use App\Modules\Finance\Database\Seeders\CashCategorySeeder;
use App\Modules\Finance\Models\CashCategory;
use App\Modules\Finance\Models\CashTransaction;
use App\Modules\Finance\Models\ExpenseRequest;
use App\Modules\Finance\Services\ExpenseRequestService;
use App\Modules\Finance\Services\FinanceException;
use App\Modules\Platform\Database\Seeders\PermissionCatalogSeeder;
use App\Modules\Platform\Database\Seeders\RoleSeeder;
use App\Modules\Platform\Models\Role;
use App\Modules\Platform\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Pengajuan & persetujuan biaya (domain K item F).
 *
 * Yang paling perlu dikunci: tiga tahapnya memang tiga orang berbeda, dan
 * itu ditegakkan DI DALAM service — bukan hanya lewat gerbang peran, yang
 * runtuh begitu ada orang memegang dua peran sekaligus.
 */
class ExpenseRequestTest extends TestCase
{
    use RefreshDatabase;

    private ExpenseRequestService $pengajuan;
    private User $pengaju;
    private User $penyetuju;
    private User $validator;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([PermissionCatalogSeeder::class, RoleSeeder::class, CashCategorySeeder::class]);

        $this->pengajuan = app(ExpenseRequestService::class);

        foreach (['pengaju', 'penyetuju', 'validator'] as $peran) {
            $this->{$peran} = User::query()->create([
                'username' => 'uji-' . $peran, 'name' => ucfirst($peran), 'password' => 'password', 'is_active' => true,
            ]);
            $this->{$peran}->roles()->attach(Role::query()->where('code', 'petugas-keuangan')->firstOrFail());
        }
    }

    // ------------------------------------------------------------------ alur

    #[Test]
    public function pengajuan_tercatat_dengan_status_diajukan(): void
    {
        $p = $this->ajukan(5_000_000);

        $this->assertSame(ExpenseRequest::DIAJUKAN, $p->status);
        $this->assertNull($p->approved_amount, 'Belum ada yang menyetujui');
        $this->assertStringStartsWith('PB', $p->request_number);
    }

    #[Test]
    public function pengajuan_harus_memakai_pos_pengeluaran_bukan_pemasukan(): void
    {
        $this->expectException(FinanceException::class);
        $this->expectExceptionMessage('pos pengeluaran');

        $this->pengajuan->submit([
            'unit_name' => 'IGD',
            'requested_on' => now()->toDateString(),
            'purpose' => 'Salah pos',
            'requested_amount' => 1_000_000,
            'category_id' => $this->pos('KM-01')->id,   // pos pemasukan
        ], $this->pengaju->id, $this->pengaju->name);
    }

    /**
     * Inti item ini, bagian pertama: pengaju tidak boleh menyetujui
     * pengajuannya sendiri.
     */
    #[Test]
    public function pengaju_tidak_bisa_menyetujui_pengajuannya_sendiri(): void
    {
        $p = $this->ajukan(5_000_000);

        $this->expectException(FinanceException::class);
        $this->expectExceptionMessage('pengajunya sendiri');

        $this->pengajuan->approve($p, null, null, $this->pengaju->id);
    }

    /** Inti item ini, bagian kedua: penyetuju tidak boleh memvalidasi persetujuannya sendiri. */
    #[Test]
    public function penyetuju_tidak_bisa_memvalidasi_persetujuannya_sendiri(): void
    {
        $p = $this->ajukan(5_000_000);
        $p = $this->pengajuan->approve($p, null, null, $this->penyetuju->id);

        $this->expectException(FinanceException::class);
        $this->expectExceptionMessage('penyetujunya sendiri');

        $this->pengajuan->validateApproval($p, $this->penyetuju->id);
    }

    #[Test]
    public function alur_lengkap_tiga_orang_berbeda_berhasil(): void
    {
        $p = $this->ajukan(5_000_000);

        $p = $this->pengajuan->approve($p, null, null, $this->penyetuju->id);
        $this->assertSame(ExpenseRequest::DISETUJUI, $p->status);

        $p = $this->pengajuan->validateApproval($p, $this->validator->id);
        $this->assertSame(ExpenseRequest::TERVALIDASI, $p->status);
    }

    #[Test]
    public function tahap_tidak_boleh_dilompati(): void
    {
        $p = $this->ajukan(5_000_000);

        $this->expectException(FinanceException::class);
        $this->expectExceptionMessage("tidak bisa dilanjutkan ke 'tervalidasi'");

        $this->pengajuan->validateApproval($p, $this->validator->id);
    }

    // -------------------------------------------------------------- nilai

    /**
     * Nilai yang disetujui disimpan TERPISAH, supaya pemotongannya tetap
     * terlihat — selisih itulah angka yang dicari saat menyusun anggaran.
     */
    #[Test]
    public function pemotongan_nilai_tetap_terlihat(): void
    {
        $p = $this->ajukan(5_000_000);
        $p = $this->pengajuan->approve($p, 3_500_000, 'dana terbatas', $this->penyetuju->id);

        $this->assertSame('5000000.00', $p->requested_amount, 'Nilai pengajuan tidak ditimpa');
        $this->assertSame('3500000.00', $p->approved_amount);
        $this->assertSame(1_500_000.0, $p->reduction());
    }

    #[Test]
    public function persetujuan_tidak_boleh_melebihi_yang_diajukan(): void
    {
        $p = $this->ajukan(5_000_000);

        $this->expectException(FinanceException::class);
        $this->expectExceptionMessage('tidak boleh melebihi');

        $this->pengajuan->approve($p, 7_000_000, null, $this->penyetuju->id);
    }

    // ---------------------------------------------------------- pencairan

    /**
     * Pencairan menulis ke KAS, bukan menyimpan nilainya sendiri — uang
     * yang keluar hanya boleh punya satu pencatatan.
     */
    #[Test]
    public function pencairan_menulis_transaksi_kas_sebesar_yang_disetujui(): void
    {
        $p = $this->ajukan(5_000_000);
        $p = $this->pengajuan->approve($p, 3_500_000, null, $this->penyetuju->id);
        $p = $this->pengajuan->validateApproval($p, $this->validator->id);

        $p = $this->pengajuan->disburse($p, ['paid_on' => now()->toDateString()], $this->validator->id, $this->validator->name);

        $this->assertSame(ExpenseRequest::DICAIRKAN, $p->status);
        $this->assertNotNull($p->cash_transaction_id);

        $kas = CashTransaction::query()->findOrFail($p->cash_transaction_id);

        $this->assertSame(CashCategory::KELUAR, $kas->direction);
        $this->assertSame('3500000.00', $kas->amount, 'Yang dicairkan yang DISETUJUI, bukan yang diajukan');
        $this->assertStringContainsString($p->request_number, $kas->description);
    }

    #[Test]
    public function pengajuan_tanpa_pos_tidak_bisa_dicairkan(): void
    {
        $p = $this->pengajuan->submit([
            'unit_name' => 'IGD',
            'requested_on' => now()->toDateString(),
            'purpose' => 'Tanpa pos',
            'requested_amount' => 1_000_000,
        ], $this->pengaju->id, $this->pengaju->name);

        $p = $this->pengajuan->approve($p, null, null, $this->penyetuju->id);
        $p = $this->pengajuan->validateApproval($p, $this->validator->id);

        $this->expectException(FinanceException::class);
        $this->expectExceptionMessage('pos pengeluaran');

        $this->pengajuan->disburse($p, ['paid_on' => now()->toDateString()], $this->validator->id);
    }

    #[Test]
    public function pengajuan_yang_ditolak_tidak_bisa_dicairkan(): void
    {
        $p = $this->ajukan(5_000_000);
        $p = $this->pengajuan->reject($p, 'anggaran habis', $this->penyetuju->id);

        $this->expectException(FinanceException::class);

        $this->pengajuan->disburse($p, ['paid_on' => now()->toDateString()], $this->validator->id);
    }

    // ---------------------------------------------------------------- rekap

    #[Test]
    public function rekap_per_unit_menampilkan_selisihnya(): void
    {
        $a = $this->ajukan(5_000_000, 'IGD');
        $this->pengajuan->approve($a, 3_500_000, null, $this->penyetuju->id);

        $b = $this->ajukan(2_000_000, 'Laboratorium');
        $this->pengajuan->approve($b, 2_000_000, null, $this->penyetuju->id);

        $rekap = $this->pengajuan->recapByUnit()->keyBy('unit_name');

        $this->assertSame(1_500_000.0, (float) $rekap['IGD']->selisih);
        $this->assertSame(0.0, (float) $rekap['Laboratorium']->selisih);
    }

    #[Test]
    public function penyaring_status_berlaku_pada_daftarnya(): void
    {
        $a = $this->ajukan(5_000_000);
        $this->pengajuan->approve($a, null, null, $this->penyetuju->id);
        $this->ajukan(2_000_000);

        $this->assertCount(2, $this->pengajuan->requests());
        $this->assertCount(1, $this->pengajuan->requests('disetujui'));
        $this->assertCount(1, $this->pengajuan->requests('diajukan'));
    }

    // ----------------------------------------------------------------- layar

    #[Test]
    public function layar_pengajuan_hanya_untuk_yang_berhak(): void
    {
        $this->actingAs($this->pengaju)->get(route('pengajuan-biaya.index'))->assertOk();

        $dokter = User::query()->create([
            'username' => 'uji-dokter-biaya', 'name' => 'Dokter', 'password' => 'password', 'is_active' => true,
        ]);
        $dokter->roles()->attach(Role::query()->where('code', 'dokter')->firstOrFail());

        $this->actingAs($dokter)->get(route('pengajuan-biaya.index'))->assertForbidden();
    }

    /**
     * Yang penting: bahkan ketika satu orang memegang SEMUA gerbang —
     * lazim di rumah sakit kecil — sistem tetap menolaknya menyetujui
     * pengajuannya sendiri.
     */
    #[Test]
    public function satu_orang_bergerbang_lengkap_tetap_ditolak_lewat_http(): void
    {
        $this->actingAs($this->pengaju)
            ->post(route('pengajuan-biaya.simpan'), [
                'unit_name' => 'IGD',
                'requested_on' => now()->toDateString(),
                'purpose' => 'Uji',
                'requested_amount' => 1_000_000,
            ])
            ->assertRedirect();

        $p = ExpenseRequest::query()->firstOrFail();

        $this->actingAs($this->pengaju)
            ->post(route('pengajuan-biaya.setuju', $p->id))
            ->assertRedirect()
            ->assertSessionHasErrors('approved_amount');

        $this->assertSame(ExpenseRequest::DIAJUKAN, $p->refresh()->status);
    }

    #[Test]
    public function layar_menyatakan_alasan_tiga_tahapnya(): void
    {
        $this->actingAs($this->pengaju)
            ->get(route('pengajuan-biaya.index'))
            ->assertOk()
            ->assertSee('Tiga tahap, dan itu memang disengaja', false)
            ->assertSee('bukan cuma lewat hak akses', false);
    }

    // ------------------------------------------------------------------ bantu

    private function pos(string $code): CashCategory
    {
        return CashCategory::query()->where('code', $code)->firstOrFail();
    }

    private function ajukan(float $nilai, string $unit = 'IGD'): ExpenseRequest
    {
        return $this->pengajuan->submit([
            'unit_name' => $unit,
            'requested_on' => now()->toDateString(),
            'purpose' => 'Keperluan uji',
            'requested_amount' => $nilai,
            'category_id' => $this->pos('KK-01')->id,
        ], $this->pengaju->id, $this->pengaju->name);
    }
}
