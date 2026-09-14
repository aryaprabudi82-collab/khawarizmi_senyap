<?php

namespace Tests\Feature\Keuangan;

use App\Modules\Keuangan\MasterData\Application\PayerContractService;
use App\Modules\Keuangan\Shared\Domain\KeuanganException;
use App\Modules\Keuangan\Shared\Domain\Money;
use App\Modules\Platform\Database\Seeders\PermissionCatalogSeeder;
use App\Modules\Platform\Database\Seeders\RoleSeeder;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Kontrak penjamin berperiode — Modul A butir 1.8.
 *
 * DUA HAL YANG PALING PERLU DIKUNCI:
 *
 * 1. TAGIHAN DINILAI DENGAN KONTRAK YANG BERLAKU PADA TANGGAL LAYANAN,
 *    bukan kontrak yang berlaku saat laporannya dibuka. Tanpa itu,
 *    perpanjangan kontrak tahun ini diam-diam mengubah cara tagihan tahun
 *    lalu seharusnya dihitung.
 *
 * 2. BAGIAN PASIEN + BAGIAN PENJAMIN SELALU SAMA PERSIS dengan tagihannya.
 *    Pembagian persentase yang membulatkan tiap sisi sendiri-sendiri
 *    menghasilkan selisih satu sen yang menumpuk ribuan kali per bulan.
 */
class PayerContractTest extends TestCase
{
    use RefreshDatabase;

    private PayerContractService $kontrak;

    private int $penjamin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([PermissionCatalogSeeder::class, RoleSeeder::class, ReferenceDataSeeder::class]);

        $this->kontrak = app(PayerContractService::class);
        $this->penjamin = (int) DB::table('catalog.payers')->where('code', 'BPJS')->value('id');
    }

    // ---------------------------------------------------------- pendaftaran

    #[Test]
    public function kontrak_baru_menutup_kontrak_berjalan(): void
    {
        $lama = $this->daftar('PKS-2025', validFrom: '2025-01-01');
        $this->assertNull($lama->valid_until);

        $this->daftar('PKS-2026', validFrom: '2026-01-01');

        $this->assertSame('2025-12-31',
            DB::table('keuangan_master.payer_contracts')->find($lama->id)->valid_until,
            'Kontrak lama ditutup sehari sebelum yang baru berlaku');
    }

    /**
     * Kontrak baru tidak boleh mundur ke belakang kontrak berjalan —
     * tagihan di antara keduanya jadi tidak punya kontrak yang jelas.
     */
    #[Test]
    public function kontrak_baru_tidak_boleh_mundur(): void
    {
        $this->daftar('PKS-2026', validFrom: '2026-01-01');

        $this->expectException(KeuanganException::class);
        $this->expectExceptionMessage('tidak boleh mundur');

        $this->daftar('PKS-2025', validFrom: '2025-06-01');
    }

    #[Test]
    public function penjamin_yang_tidak_ada_ditolak(): void
    {
        $this->expectException(KeuanganException::class);
        $this->expectExceptionMessage('tidak ditemukan');

        $this->kontrak->daftarkan([
            'payer_id' => 999999,
            'contract_number' => 'X',
            'name' => 'X',
        ]);
    }

    // --------------------------------------------------- resolusi per tanggal

    /** INTI BUTIR 1.8. */
    #[Test]
    public function kontrak_diresolusi_menurut_tanggal_layanan(): void
    {
        $this->daftar('PKS-2025', validFrom: '2025-01-01', persen: '10');
        $this->daftar('PKS-2026', validFrom: '2026-01-01', persen: '20');

        $this->assertSame('PKS-2025', $this->kontrak->berlakuPada($this->penjamin, '2025-06-15')->contract_number);
        $this->assertSame('PKS-2026', $this->kontrak->berlakuPada($this->penjamin, '2026-06-15')->contract_number);
    }

    #[Test]
    public function sebelum_kontrak_pertama_tidak_ada_kontrak(): void
    {
        $this->daftar('PKS-2026', validFrom: '2026-01-01');

        $this->assertNull($this->kontrak->berlakuPada($this->penjamin, '2025-12-31'));
    }

    // -------------------------------------------------------- cost-sharing

    /**
     * TIDAK ADA KONTRAK MENGEMBALIKAN NULL, BUKAN NOL. Nol berarti "pasien
     * tidak menanggung apa-apa" — pernyataan yang sangat berbeda dari
     * "belum diketahui kontraknya".
     */
    #[Test]
    public function tanpa_kontrak_bagian_pasien_null_bukan_nol(): void
    {
        $this->assertNull(
            $this->kontrak->bagianPasien($this->penjamin, '2026-06-01', Money::tagihan('1000000'))
        );
    }

    #[Test]
    public function basis_persentase_menghitung_bagian_pasien(): void
    {
        $this->daftar('PKS-A', persen: '10');

        $bagian = $this->kontrak->bagianPasien($this->penjamin, now()->toDateString(), Money::tagihan('1000000'));

        $this->assertSame('100000.00', (string) $bagian);
    }

    /**
     * BAGIAN PASIEN + BAGIAN PENJAMIN = TAGIHAN, tepat. Diuji pada angka
     * yang sengaja tidak habis dibagi.
     */
    #[Test]
    public function bagian_pasien_dan_penjamin_selalu_berjumlah_tepat(): void
    {
        $this->daftar('PKS-B', persen: '33.33');

        $tagihan = Money::tagihan('1000000.01');
        $pasien = $this->kontrak->bagianPasien($this->penjamin, now()->toDateString(), $tagihan);
        $penjamin = $tagihan->kurang($pasien);

        $this->assertTrue($tagihan->samaDengan($pasien->tambah($penjamin)),
            'Pasien '.$pasien.' + penjamin '.$penjamin.' harus sama persis dengan '.$tagihan);
    }

    #[Test]
    public function basis_nominal_tetap(): void
    {
        $this->daftar('PKS-C', basis: PayerContractService::BASIS_NOMINAL, nominal: '50000');

        $this->assertSame('50000.00',
            (string) $this->kontrak->bagianPasien($this->penjamin, now()->toDateString(), Money::tagihan('1000000')));
    }

    /**
     * Bagian pasien tidak boleh melebihi tagihannya sendiri. Nominal tetap
     * Rp 50.000 atas tagihan Rp 30.000 akan membuat pasien membayar lebih
     * besar daripada layanannya — dan penjamin membayar negatif.
     */
    #[Test]
    public function bagian_pasien_tidak_melebihi_tagihan(): void
    {
        $this->daftar('PKS-D', basis: PayerContractService::BASIS_NOMINAL, nominal: '50000');

        $this->assertSame('30000.00',
            (string) $this->kontrak->bagianPasien($this->penjamin, now()->toDateString(), Money::tagihan('30000')));
    }

    #[Test]
    public function basis_persentase_berbatas_dipotong_di_plafon(): void
    {
        $this->daftar('PKS-E',
            basis: PayerContractService::BASIS_PERSENTASE_BERBATAS,
            persen: '20', cap: '150000');

        // 20% dari 1.000.000 = 200.000, dipotong jadi 150.000.
        $this->assertSame('150000.00',
            (string) $this->kontrak->bagianPasien($this->penjamin, now()->toDateString(), Money::tagihan('1000000')));

        // 20% dari 500.000 = 100.000, di bawah batas, tidak dipotong.
        $this->assertSame('100000.00',
            (string) $this->kontrak->bagianPasien($this->penjamin, now()->toDateString(), Money::tagihan('500000')));
    }

    /**
     * Basis yang tidak lengkap DITOLAK. Kontrak berbasis persentase tanpa
     * angka persennya akan menghitung bagian pasien sebagai NOL — pasien
     * tidak ditagih apa-apa, tidak ada galat, dan selisihnya baru ketahuan
     * saat rekonsiliasi penjamin.
     */
    #[Test]
    public function basis_tanpa_angkanya_ditolak(): void
    {
        $this->expectException(KeuanganException::class);
        $this->expectExceptionMessage('menuntut');

        $this->kontrak->daftarkan([
            'payer_id' => $this->penjamin,
            'contract_number' => 'PKS-F',
            'name' => 'Tanpa angka',
            'cost_sharing_basis' => PayerContractService::BASIS_PERSENTASE,
        ]);
    }

    /** Basis data menolaknya juga, melewati seluruh pemeriksaan PHP. */
    #[Test]
    public function basis_data_juga_menolak_basis_tidak_lengkap(): void
    {
        $this->expectException(QueryException::class);

        DB::table('keuangan_master.payer_contracts')->insert([
            'payer_id' => $this->penjamin,
            'contract_number' => 'BYPASS',
            'name' => 'Lewat service',
            'valid_from' => now()->toDateString(),
            'cost_sharing_basis' => PayerContractService::BASIS_PERSENTASE,
            'cost_sharing_percent' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    // -------------------------------------------------------- pengecualian

    #[Test]
    public function pengecualian_per_golongan_terdeteksi(): void
    {
        $k = $this->daftar('PKS-G');
        $this->kontrak->kecualikan($k->id, 'Kosmetik tidak ditanggung', golongan: 'tindakan');

        $this->assertNotNull($this->kontrak->dikecualikan($k->id, null, 'tindakan'));
        $this->assertNull($this->kontrak->dikecualikan($k->id, null, 'obat'));
    }

    /**
     * Pengecualian yang tidak menunjuk apa pun tidak mengecualikan apa pun
     * — ia cuma membuat daftar tampak lebih panjang daripada kenyataannya.
     */
    #[Test]
    public function pengecualian_tanpa_sasaran_ditolak(): void
    {
        $k = $this->daftar('PKS-H');

        $this->expectException(KeuanganException::class);
        $this->expectExceptionMessage('SATU sasaran');

        $this->kontrak->kecualikan($k->id, 'Tanpa sasaran');
    }

    // ------------------------------------------------------- akan berakhir

    /**
     * Kontrak yang habis tanpa ada yang memperpanjang membuat seluruh
     * tagihan penjamin itu kehilangan dasar — dan itu baru ketahuan saat
     * klaimnya ditolak.
     */
    #[Test]
    public function kontrak_yang_akan_berakhir_dilaporkan(): void
    {
        $k = $this->daftar('PKS-I');

        DB::table('keuangan_master.payer_contracts')->where('id', $k->id)
            ->update(['valid_until' => now()->addDays(10)->toDateString()]);

        $this->assertCount(1, $this->kontrak->akanBerakhir(30));
        $this->assertCount(0, $this->kontrak->akanBerakhir(5));
    }

    // ------------------------------------------------------------ pembantu

    /**
     * `$validFrom` bawaannya SETAHUN LALU supaya kontraknya sudah berlaku
     * saat `now()` dipakai memeriksa bagian pasien.
     *
     * Percobaan pertama memakai tanggal tetap '2026-01-01' lalu
     * menggantinya jadi setahun lalu bila nilainya masih bawaan — dan itu
     * diam-diam MENGABAIKAN tanggal yang sengaja dikirim uji lain yang
     * kebetulan sama. Uji yang mengabaikan argumennya sendiri akan
     * menguji hal yang berbeda dari yang tertulis di namanya.
     */
    private function daftar(
        string $nomor,
        ?string $validFrom = null,
        string $basis = PayerContractService::BASIS_PERSENTASE,
        ?string $persen = '10',
        ?string $nominal = null,
        ?string $cap = null,
    ): object {
        $validFrom ??= now()->subYear()->toDateString();

        return $this->kontrak->daftarkan([
            'payer_id' => $this->penjamin,
            'contract_number' => $nomor,
            'name' => 'Kontrak '.$nomor,
            'valid_from' => $validFrom,
            'cost_sharing_basis' => $basis,
            'cost_sharing_percent' => $basis === PayerContractService::BASIS_NOMINAL ? null : $persen,
            'cost_sharing_amount' => $nominal,
            'cost_sharing_cap' => $cap,
        ]);
    }
}
