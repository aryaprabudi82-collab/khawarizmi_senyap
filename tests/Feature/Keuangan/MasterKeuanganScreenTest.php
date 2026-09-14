<?php

namespace Tests\Feature\Keuangan;

use App\Modules\Keuangan\MasterData\Domain\ChargeItem;
use App\Modules\Platform\Database\Seeders\PermissionCatalogSeeder;
use App\Modules\Platform\Database\Seeders\RoleSeeder;
use App\Modules\Platform\Database\Seeders\UserSeeder;
use App\Modules\Platform\Models\User;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Layar master keuangan — DITELUSURI LEWAT HTTP, bukan lewat service.
 *
 * MENGAPA UJI INI ADA, dan mengapa bentuknya begini.
 *
 * Bug pemilih obat lolos karena seluruh uji saya memanggil service
 * langsung — jalur yang tidak pernah ditempuh manusia. Servicenya benar,
 * ujinya hijau, dan layarnya tetap menolak isian yang benar. Saya sendiri
 * yang menulis di commit bahwa itulah titik butanya, lalu terus bekerja
 * dengan cara yang sama.
 *
 * Maka uji ini menekan yang hanya bisa gagal lewat HTTP:
 *
 *   - view-nya ada dan ter-render (nama view salah = 500, dan service
 *     yang sempurna tidak menolong siapa pun),
 *   - seluruh variabel yang dipakai Blade benar-benar dikirim controller,
 *   - gerbang haknya benar — bukan hanya "ada gerbang",
 *   - nama rute yang dirujuk menu memang terdaftar,
 *   - jalur TULIS bekerja dari formulir, bukan hanya dari service.
 */
class MasterKeuanganScreenTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        /*
         * UserSeeder ikut, dan itu memang dibutuhkan: uji ini menelusuri
         * layar sebagai PENGGUNA SUNGGUHAN dengan peran sungguhan, bukan
         * sebagai pengguna karangan yang diberi seluruh hak. Pengguna
         * karangan akan membuat gerbang haknya tidak pernah benar-benar
         * diuji — dan gerbang yang tidak diuji adalah gerbang yang baru
         * ketahuan salah saat orang yang tidak berhak membuka layarnya.
         */
        $this->seed([
            PermissionCatalogSeeder::class,
            RoleSeeder::class,
            UserSeeder::class,
            ReferenceDataSeeder::class,
        ]);
    }

    // ------------------------------------------------------------- gerbang

    /**
     * Petugas Keuangan BISA membuka seluruh layar.
     *
     * Ini setengah pemeriksaan yang penting. Gerbang yang menolak semua
     * orang juga "punya gerbang" — dan layar yang tidak bisa dibuka
     * siapa pun sama tidak bergunanya dengan layar yang tidak ada.
     */
    #[Test]
    public function petugas_keuangan_bisa_membuka_seluruh_layar(): void
    {
        $this->actingAs($this->penggunaKeuangan());

        foreach ($this->layar() as $nama => $rute) {
            $this->get($rute)->assertOk("Layar '{$nama}' tidak terbuka untuk Petugas Keuangan");
        }
    }

    /**
     * Peran yang tidak berhak DITOLAK.
     *
     * Setengah lainnya: tanpa ini, gerbang yang tidak pernah menolak
     * siapa pun akan lolos uji di atas dengan sempurna.
     */
    #[Test]
    public function peran_tanpa_hak_ditolak(): void
    {
        $this->actingAs($this->pengguna('parkir1'));

        foreach ($this->layar() as $nama => $rute) {
            $this->get($rute)->assertForbidden("Layar '{$nama}' terbuka untuk peran yang tidak berhak");
        }
    }

    #[Test]
    public function tamu_diarahkan_ke_halaman_masuk(): void
    {
        $this->get(route('master-keuangan.index'))->assertRedirect(route('masuk'));
    }

    // --------------------------------------------------------- layar terbuka

    /**
     * Tiap layar ter-RENDER, bukan sekadar mengembalikan 200.
     *
     * Blade yang merujuk variabel tak terkirim melempar galat saat
     * di-render, bukan saat controller mengembalikannya — jadi memeriksa
     * isinya adalah satu-satunya cara membuktikan view-nya benar-benar
     * jadi.
     */
    #[Test]
    public function layar_ringkasan_menampilkan_cakupan_penautan(): void
    {
        $this->actingAs($this->penggunaKeuangan())
            ->get(route('master-keuangan.index'))
            ->assertOk()
            ->assertSee('Penautan tarif per konteks sumber')
            ->assertSee('catalog')
            ->assertSee('Item CDM berjalan');
    }

    #[Test]
    public function layar_charge_master_terbuka_walau_katalog_kosong(): void
    {
        $this->actingAs($this->penggunaKeuangan())
            ->get(route('master-keuangan.item'))
            ->assertOk()
            ->assertSee('Charge Description Master')
            ->assertSee('Belum ada item yang berlaku');
    }

    #[Test]
    public function layar_kontrak_penjamin_terbuka(): void
    {
        $this->actingAs($this->penggunaKeuangan())
            ->get(route('master-keuangan.kontrak'))
            ->assertOk()
            ->assertSee('Kontrak Penjamin')
            ->assertSee('Daftarkan kontrak baru');
    }

    #[Test]
    public function layar_pusat_biaya_terbuka(): void
    {
        $this->actingAs($this->penggunaKeuangan())
            ->get(route('master-keuangan.pusat-biaya'))
            ->assertOk()
            ->assertSee('Daftarkan pusat baru')
            ->assertSee('program-center');
    }

    // ------------------------------------------------------------ jalur tulis

    /**
     * PENAUTAN LEWAT FORMULIR, bukan lewat service.
     *
     * Ini jalur yang ditempuh petugas sungguhan: menekan tombol di layar.
     */
    #[Test]
    public function penautan_lewat_formulir_menghasilkan_item_nonaktif(): void
    {
        $this->actingAs($this->penggunaKeuangan())
            ->post(route('master-keuangan.item.tautkan'), ['konteks' => 'catalog'])
            ->assertRedirect()
            ->assertSessionHas('sukses');

        $item = ChargeItem::query()->where('source_context', 'catalog')->get();

        $this->assertGreaterThan(0, $item->count(), 'Penautan lewat formulir tidak menghasilkan item');
        $this->assertTrue($item->every(fn ($i) => $i->is_active === false),
            'Sapuan TIDAK BOLEH mengaktifkan apa pun — item aktif tanpa akun berarti uang masuk '
            .'yang tidak pernah sampai ke buku besar');
        $this->assertTrue($item->every(fn ($i) => $i->revenue_account_id === null));
    }

    #[Test]
    public function konteks_sumber_yang_tidak_dikenal_ditolak_dengan_pesan(): void
    {
        $this->actingAs($this->penggunaKeuangan())
            ->post(route('master-keuangan.item.tautkan'), ['konteks' => 'ngawur'])
            ->assertRedirect()
            ->assertSessionHas('gagal');

        $this->assertSame(0, ChargeItem::query()->count());
    }

    /**
     * MENGAKTIFKAN ITEM TANPA AKUN DITOLAK, dan pesannya menjelaskan.
     *
     * CHECK di basis data menolaknya dengan benar, tapi galat SQL mentah
     * tidak menolong petugas yang sedang mengisi formulir — dan yang
     * menerima pesan itu adalah orang, bukan mesin.
     */
    #[Test]
    public function mengaktifkan_item_tanpa_akun_ditolak_dengan_pesan_yang_menjelaskan(): void
    {
        $this->actingAs($this->penggunaKeuangan())
            ->post(route('master-keuangan.item.tautkan'), ['konteks' => 'catalog']);

        $item = ChargeItem::query()->where('source_context', 'catalog')->firstOrFail();

        $respons = $this->actingAs($this->penggunaKeuangan())
            ->post(route('master-keuangan.item.aktifkan', $item->id))
            ->assertRedirect();

        $respons->assertSessionHas('gagal');

        $this->assertStringContainsString('akun', strtolower((string) session('gagal')),
            'Pesannya harus menyebut akun — "terjadi kesalahan" tidak menolong siapa pun');

        $this->assertFalse($item->refresh()->is_active);
    }

    /** Pemetaan akun lewat formulir, lalu item bisa diaktifkan. */
    #[Test]
    public function memetakan_akun_lalu_mengaktifkan_lewat_formulir(): void
    {
        $this->seed(\App\Modules\Finance\Database\Seeders\ChartOfAccountsSeeder::class);

        $this->actingAs($this->penggunaKeuangan())
            ->post(route('master-keuangan.item.tautkan'), ['konteks' => 'catalog']);

        /*
         * Item TINDAKAN sengaja dipilih, bukan obat: obat/BHP/alkes juga
         * menuntut akun beban pokok, dan uji ini sedang menguji jalur
         * paling sederhana.
         */
        $item = ChargeItem::query()
            ->where('source_context', 'catalog')
            ->where('golongan', ChargeItem::GOL_TINDAKAN)
            ->firstOrFail();

        $akunPendapatan = (int) DB::table('finance.v_account')
            ->where('is_postable', true)
            ->where('type', 'pendapatan')
            ->value('id');

        $this->actingAs($this->penggunaKeuangan())
            ->post(route('master-keuangan.item.petakan', $item->id), [
                'revenue_account_id' => $akunPendapatan,
            ])
            ->assertRedirect()
            ->assertSessionHas('sukses');

        $this->assertSame($akunPendapatan, (int) $item->refresh()->revenue_account_id);
        $this->assertFalse($item->is_active, 'Memetakan akun TIDAK ikut mengaktifkan');

        $this->actingAs($this->penggunaKeuangan())
            ->post(route('master-keuangan.item.aktifkan', $item->id))
            ->assertRedirect()
            ->assertSessionHas('sukses');

        $this->assertTrue($item->refresh()->is_active);
    }

    /**
     * Kontrak berbasis persentase TANPA angka persennya ditolak.
     *
     * Kalau lolos, bagian pasien terhitung nol: pasien tidak ditagih
     * apa-apa, tidak ada galat, dan selisihnya baru ketahuan saat
     * rekonsiliasi penjamin.
     */
    #[Test]
    public function kontrak_persentase_tanpa_angka_persennya_ditolak(): void
    {
        $payerId = (int) DB::table('catalog.v_payer_summary')->value('id');

        $this->actingAs($this->penggunaKeuangan())
            ->post(route('master-keuangan.kontrak.simpan'), [
                'payer_id' => $payerId,
                'contract_number' => 'UJI-001',
                'name' => 'Kontrak Uji',
                'valid_from' => now()->toDateString(),
                'cost_sharing_basis' => 'persentase',
                // cost_sharing_percent sengaja tidak dikirim
            ])
            ->assertRedirect()
            ->assertSessionHas('gagal');

        $this->assertSame(0, DB::table('keuangan_master.payer_contracts')->count());
    }

    /**
     * Pusat pendapatan BERDRIVER ditolak lewat formulir.
     *
     * Memberinya driver menyiratkan biayanya dialokasikan lagi ke tempat
     * lain — dan alokasi yang berputar tidak pernah selesai dihitung.
     */
    #[Test]
    public function pusat_pendapatan_berdriver_ditolak_lewat_formulir(): void
    {
        $this->actingAs($this->penggunaKeuangan())
            ->post(route('master-keuangan.pusat-biaya.simpan'), [
                'code' => 'RC-UJI',
                'name' => 'Poli Uji',
                'jenis' => 'revenue-center',
                'cost_driver' => 'luas-lantai',
                'valid_from' => now()->toDateString(),
            ])
            ->assertRedirect()
            ->assertSessionHas('gagal');

        $this->assertSame(0, DB::table('keuangan_master.cost_centers')->count());
    }

    #[Test]
    public function pusat_biaya_sah_tersimpan_lewat_formulir(): void
    {
        $this->actingAs($this->penggunaKeuangan())
            ->post(route('master-keuangan.pusat-biaya.simpan'), [
                'code' => 'CC-LAUNDRY',
                'name' => 'Laundry',
                'jenis' => 'cost-center',
                'cost_driver' => 'berat-cucian',
                'valid_from' => now()->toDateString(),
            ])
            ->assertRedirect()
            ->assertSessionHas('sukses');

        $this->assertSame(1, DB::table('keuangan_master.cost_centers')
            ->where('code', 'CC-LAUNDRY')->count());
    }

    // -------------------------------------------------------------- pembantu

    /** @return array<string, string> */
    private function layar(): array
    {
        return [
            'ringkasan' => route('master-keuangan.index'),
            'charge master' => route('master-keuangan.item'),
            'kontrak penjamin' => route('master-keuangan.kontrak'),
            'pusat biaya' => route('master-keuangan.pusat-biaya'),
        ];
    }

    private function penggunaKeuangan(): User
    {
        return $this->pengguna('keuangan1');
    }

    private function pengguna(string $username): User
    {
        return User::query()->where('username', $username)->firstOrFail();
    }
}
