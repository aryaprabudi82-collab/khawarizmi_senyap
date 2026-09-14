<?php

namespace Tests\Feature\Keuangan;

use App\Modules\Keuangan\MasterData\Application\ChargeItemLinker;
use App\Modules\Keuangan\MasterData\Application\ChargeMasterService;
use App\Modules\Keuangan\MasterData\Application\TariffSourceRegistry;
use App\Modules\Keuangan\MasterData\Domain\ChargeItem;
use App\Modules\Keuangan\Shared\Domain\KeuanganException;
use App\Modules\Keuangan\Shared\Domain\Money;
use App\Modules\Platform\Database\Seeders\PermissionCatalogSeeder;
use App\Modules\Platform\Database\Seeders\RoleSeeder;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Penautan tarif lama ke CDM — gate Wave 1.
 *
 * YANG PALING PENTING DIKUNCI DI SINI bukan "penautannya berhasil",
 * melainkan tiga hal yang kalau salah TIDAK MELEMPAR GALAT APA PUN:
 *
 * 1. Tarif yang belum diisi mengembalikan NULL, bukan nol. Nol berarti
 *    "gratis" dan akan diterbitkan sebagai tagihan senilai nol tanpa ada
 *    yang curiga.
 *
 * 2. Item hasil penautan lahir NONAKTIF dan belum dipetakan akun. Kalau
 *    penautan ikut mengaktifkan, uang masuk tanpa pernah sampai ke buku
 *    besar.
 *
 * 3. Resolver membaca view yang BENAR. Inpatient punya tiga view berkamar
 *    dan dua di antaranya menghasilkan angka yang terlihat wajar tapi
 *    salah — biaya oksigen ditagih sebagai harga kamar, atau tarif VIP
 *    dihitung dari rata-rata kelas.
 */
class TariffLinkingTest extends TestCase
{
    use RefreshDatabase;

    private TariffSourceRegistry $registry;

    private ChargeItemLinker $penaut;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([PermissionCatalogSeeder::class, RoleSeeder::class, ReferenceDataSeeder::class]);

        $this->registry = app(TariffSourceRegistry::class);
        $this->penaut = new ChargeItemLinker($this->registry, app(ChargeMasterService::class));

        $this->siapkanKamar();
        $this->siapkanObat();
    }

    /**
     * Kamar dan obat DIBUAT DI SINI, tidak diambil dari seeder.
     *
     * `ReferenceDataSeeder` tidak memuat keduanya, dan uji yang
     * mengandaikan data seeder akan lulus atau gagal menurut isi seeder —
     * bukan menurut benar-tidaknya kode yang diuji. Uji yang pecah saat
     * seeder berubah tidak mengatakan apa-apa tentang penautannya.
     */
    private function siapkanKamar(): void
    {
        $unitId = DB::table('organization.units')->where('is_active', true)->value('id');

        DB::table('inpatient.rooms')->insert([
            $this->kamar('VIP-01', 'vip', '1200000.00', $unitId),
            $this->kamar('K1-01', 'kelas-1', '600000.00', $unitId),
            $this->kamar('K3-01', 'kelas-3', '250000.00', $unitId),
        ]);
    }

    /** @return array<string, mixed> */
    private function kamar(string $nomor, string $kelas, string $tarif, ?int $unitId): array
    {
        return [
            'room_number' => $nomor,
            'room_class' => $kelas,
            'unit_id' => $unitId,
            'unit_name' => 'Rawat Inap',
            'daily_rate' => $tarif,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ];
    }

    private function siapkanObat(): void
    {
        DB::table('pharmacy.drugs')->insert([
            [
                'code' => 'OBT-UJI-01',
                'name' => 'Parasetamol 500 mg',
                'category' => 'obat',
                'unit' => 'tablet',
                'sell_price' => '1333.33',
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'code' => 'BHP-UJI-01',
                'name' => 'Kasa Steril 10x10',
                'category' => 'bhp',
                'unit' => 'lembar',
                'sell_price' => '5000.00',
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'code' => 'ALK-UJI-01',
                'name' => 'Kateter Foley 16',
                'category' => 'alkes',
                'unit' => 'buah',
                'sell_price' => '35000.00',
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);
    }

    // ------------------------------------------------------------- registry

    #[Test]
    public function kelima_konteks_sumber_punya_resolver(): void
    {
        $this->assertSame(
            ['catalog', 'inpatient', 'parking', 'pharmacy', 'retail'],
            $this->registry->konteksTerdaftar(),
            'Konteks tanpa resolver berarti ada golongan item yang tarifnya tidak bisa ditanyakan siapa pun'
        );
    }

    /**
     * Konteks tanpa resolver MELEMPAR, tidak mengembalikan null.
     * Mengembalikan null akan membuatnya tampak sama dengan "tarifnya
     * belum diisi", dan cacat pemasangannya bertahan sampai ada yang
     * membandingkan angka.
     */
    #[Test]
    public function konteks_tanpa_resolver_melempar_bukan_mengembalikan_null(): void
    {
        $item = app(ChargeMasterService::class)->daftarkan([
            'code' => 'MAN-MATERAI',
            'name' => 'Bea Materai',
            'golongan' => ChargeItem::GOL_ADMINISTRASI,
            'source_context' => 'manual',
        ]);

        $this->expectException(KeuanganException::class);
        $this->expectExceptionMessage('belum punya resolver tarif');

        $this->registry->tarif($item, now()->toDateString());
    }

    // --------------------------------------------------------- penautan

    #[Test]
    public function sapuan_menaut_layanan_katalog_dan_kamar(): void
    {
        $hasil = $this->penaut->sapu();

        $this->assertSame(11, $hasil['per_konteks']['catalog'] ?? 0,
            'Sebelas layanan katalog harus tertaut');
        $this->assertSame(3, $hasil['per_konteks']['inpatient'] ?? 0,
            'Tiga kamar harus tertaut');
        $this->assertSame(3, $hasil['per_konteks']['pharmacy'] ?? 0,
            'Tiga item farmasi harus tertaut');
        $this->assertSame([], $hasil['galat']);
    }

    /**
     * GOLONGAN DIAMBIL DARI KATEGORI FARMASI, tidak diseragamkan jadi
     * "obat".
     *
     * Golongan menentukan akun beban pokok mana yang wajib. Menggolongkan
     * kasa steril sebagai obat membuat HPP-nya jatuh ke akun persediaan
     * obat — nilai persediaan farmasi di GL lalu terus melenceng dari
     * gudang, dan penyebabnya tidak terlihat di laporan mana pun karena
     * totalnya tetap masuk akal.
     */
    #[Test]
    public function golongan_farmasi_mengikuti_kategorinya(): void
    {
        $this->penaut->sapu('pharmacy');

        $golongan = ChargeItem::query()
            ->where('source_context', 'pharmacy')
            ->pluck('golongan', 'code');

        $this->assertSame(ChargeItem::GOL_OBAT, $golongan['OBT-OBT-UJI-01']);
        $this->assertSame(ChargeItem::GOL_BHP, $golongan['BHP-BHP-UJI-01']);
        $this->assertSame(ChargeItem::GOL_ALKES, $golongan['ALK-ALK-UJI-01']);
    }

    /**
     * AWALAN KODE MENGIKUTI GOLONGAN, bukan konteks sumbernya.
     *
     * Konteks `pharmacy` menaungi obat, BHP, dan alkes sekaligus. Awalan
     * per konteks akan memberi kasa steril kode `OBT-BHP-001`, yang
     * terbaca seolah ia obat — dan kode dipakai orang untuk menyebut
     * barang di kuitansi, rekonsiliasi, dan pertanyaan pasien.
     */
    #[Test]
    public function awalan_kode_mengikuti_golongan_bukan_konteks(): void
    {
        $this->penaut->sapu();

        $kode = ChargeItem::query()->pluck('code')->all();

        $this->assertContains('BHP-BHP-UJI-01', $kode,
            'Kasa steril harus berawalan BHP, bukan OBT — kode yang berbohong tentang jenis '
            .'barangnya menyesatkan tiap kali dibaca');
        $this->assertContains('AKM-VIP-01', $kode);
        $this->assertContains('ADM-REG-RALAN', $kode,
            'Registrasi adalah administrasi, bukan tindakan');
    }

    /**
     * ITEM HASIL PENAUTAN LAHIR NONAKTIF DAN BELUM DIPETAKAN.
     *
     * Menautkan berarti "barang ini punya kode global"; mengaktifkan
     * berarti "boleh ditagihkan dan akan masuk buku besar". Yang kedua
     * menuntut pemetaan akun, dan pemetaan akun adalah keputusan
     * akuntansi — bukan hasil sapuan.
     */
    #[Test]
    public function item_hasil_penautan_nonaktif_dan_belum_dipetakan(): void
    {
        $this->penaut->sapu();

        $ringkasan = $this->penaut->ringkasan();

        $this->assertSame(17, $ringkasan['total']);
        $this->assertSame(17, $ringkasan['belum_dipetakan'],
            'Seluruh item hasil sapuan menunggu keputusan pemetaan akun');
        $this->assertSame(0, $ringkasan['aktif'],
            'Sapuan TIDAK BOLEH mengaktifkan apa pun — item aktif tanpa akun berarti '
            .'uang masuk yang tidak pernah sampai ke buku besar');
    }

    /** Sapuan kedua tidak menggandakan — indeks unik parsial menjaganya. */
    #[Test]
    public function sapuan_kedua_tidak_menggandakan(): void
    {
        $this->penaut->sapu();
        $kedua = $this->penaut->sapu();

        $this->assertSame(0, $kedua['ditaut']);
        $this->assertSame(17, $this->penaut->ringkasan()['total']);
    }

    #[Test]
    public function sapuan_bisa_dibatasi_satu_konteks(): void
    {
        $hasil = $this->penaut->sapu('inpatient');

        $this->assertSame(3, $hasil['ditaut']);
        $this->assertArrayNotHasKey('catalog', $hasil['per_konteks']);
    }

    /** Kode CDM berawalan konteks — supaya kode sumber yang kebetulan sama tidak bentrok. */
    #[Test]
    public function kode_cdm_berawalan_konteks(): void
    {
        $this->penaut->sapu('catalog');

        $this->assertTrue(
            ChargeItem::query()->where('code', 'TDK-TDK-EKG')->exists(),
            'Kode layanan diberi awalan golongan supaya tidak bentrok dengan kode obat'
        );
    }

    // ----------------------------------------------------- resolusi tarif

    #[Test]
    public function tarif_layanan_diresolusi_per_penjamin_dan_tanggal(): void
    {
        $this->penaut->sapu('catalog');

        $item = ChargeItem::query()->where('code', 'TDK-TDK-EKG')->firstOrFail();

        $baris = DB::table('catalog.v_tariff')
            ->where('service_id', $item->source_id)
            ->orderBy('payer_id')
            ->first();

        $this->assertNotNull($baris, 'Uji ini butuh setidaknya satu baris tarif terisi');

        $tarif = $this->registry->tarif($item, (string) $baris->valid_from, [
            'payer_id' => $baris->payer_id,
            'care_class' => $baris->care_class,
        ]);

        $this->assertNotNull($tarif);
        $this->assertSame((string) $baris->amount, (string) $tarif);
    }

    /**
     * TANPA PENJAMIN, TARIF LAYANAN TIDAK BISA DITENTUKAN — dan jatuh ke
     * tarif umum sebagai "bawaan" adalah cara paling halus kehilangan
     * pendapatan: pasien asuransi ditagih tarif umum, selisihnya
     * ditanggung rumah sakit, dan tidak ada baris galat di mana pun.
     */
    #[Test]
    public function tarif_layanan_tanpa_penjamin_mengembalikan_null(): void
    {
        $this->penaut->sapu('catalog');

        $item = ChargeItem::query()->where('source_context', 'catalog')->firstOrFail();

        $this->assertNull($this->registry->tarif($item, now()->toDateString()));
    }

    /** Tanggal sebelum tarif berlaku mengembalikan null, bukan tarif terdekat. */
    #[Test]
    public function tanggal_sebelum_tarif_berlaku_mengembalikan_null(): void
    {
        $this->penaut->sapu('catalog');

        $baris = DB::table('catalog.v_tariff')->orderBy('valid_from')->first();
        $this->assertNotNull($baris);

        $item = ChargeItem::query()
            ->where('source_context', 'catalog')
            ->where('source_id', $baris->service_id)
            ->firstOrFail();

        $sebelum = date('Y-m-d', strtotime($baris->valid_from.' -1 day'));

        $this->assertNull($this->registry->tarif($item, $sebelum, [
            'payer_id' => $baris->payer_id,
            'care_class' => $baris->care_class,
        ]));
    }

    // ------------------------------------------------------- akomodasi

    /**
     * TARIF KAMAR DIBACA DARI v_room_rate, BUKAN v_room_class_rate.
     *
     * Yang kedua adalah RATA-RATA per kelas, dan rata-rata tidak boleh
     * jadi dasar tagihan: dua kamar VIP bertarif berbeda akan ditagih di
     * angka tengah yang tidak pernah diputuskan siapa pun, sementara
     * totalnya tetap terlihat wajar.
     */
    #[Test]
    public function tarif_kamar_memakai_nominal_kamar_bukan_rata_rata_kelas(): void
    {
        $this->penaut->sapu('inpatient');

        $kamar = DB::table('inpatient.v_room_rate')->where('room_class', 'vip')->firstOrFail();

        $item = ChargeItem::query()
            ->where('source_context', 'inpatient')
            ->where('source_id', $kamar->room_id)
            ->firstOrFail();

        $tarif = $this->registry->tarif($item, now()->toDateString());

        $this->assertSame((string) $kamar->daily_rate, (string) $tarif);
    }

    #[Test]
    public function akomodasi_dikali_jumlah_hari_rawat(): void
    {
        $this->penaut->sapu('inpatient');

        $kamar = DB::table('inpatient.v_room_rate')->where('room_class', 'kelas-3')->firstOrFail();

        $item = ChargeItem::query()
            ->where('source_context', 'inpatient')
            ->where('source_id', $kamar->room_id)
            ->firstOrFail();

        $satuHari = $this->registry->tarif($item, now()->toDateString(), ['quantity' => 1]);
        $tigaBelas = $this->registry->tarif($item, now()->toDateString(), ['quantity' => 13]);

        $this->assertNotNull($satuHari);
        $this->assertTrue(
            $satuHari->kali(13)->samaDengan($tigaBelas),
            'Rawat 13 hari harus sama persis dengan 13 kali tarif satu hari'
        );
    }

    #[Test]
    public function akomodasi_nol_hari_mengembalikan_null(): void
    {
        $this->penaut->sapu('inpatient');

        $item = ChargeItem::query()->where('source_context', 'inpatient')->firstOrFail();

        $this->assertNull($this->registry->tarif($item, now()->toDateString(), ['quantity' => 0]));
    }

    // ----------------------------------------------------------- obat

    /**
     * MARKUP YANG BELUM DITETAPKAN BUKAN NOL. Menagih dengan markup nol
     * berarti menjual obat seharga modal tanpa ada yang memutuskan begitu,
     * dan marjin farmasi yang hilang tidak muncul sebagai galat — ia hanya
     * muncul sebagai laporan yang terlihat wajar dengan angka terlalu kecil.
     */
    #[Test]
    public function harga_obat_tanpa_markup_mengembalikan_null(): void
    {
        $this->penaut->sapu('pharmacy');

        $item = ChargeItem::query()->where('source_context', 'pharmacy')->firstOrFail();

        $payerId = (int) DB::table('catalog.v_payer_summary')->value('id');

        $this->assertNull(
            $this->registry->tarif($item, now()->toDateString(), ['payer_id' => $payerId]),
            'pharmacy.drug_markups kosong — harganya HARUS null, bukan harga dasar polos'
        );
    }

    #[Test]
    public function harga_obat_memakai_markup_penjamin_yang_berlaku(): void
    {
        $this->penaut->sapu('pharmacy');

        $item = ChargeItem::query()->where('source_context', 'pharmacy')->firstOrFail();

        $payerId = (int) DB::table('catalog.v_payer_summary')->value('id');

        DB::table('pharmacy.drug_markups')->insert([
            'payer_id' => $payerId,
            'payer_code' => 'UJI',
            'room_class' => null,
            'markup_percent' => '25.00',
            'effective_from' => now()->subMonth()->toDateString(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $dasar = DB::table('pharmacy.v_drug_price')->where('drug_id', $item->source_id)->value('base_price');

        $harga = $this->registry->tarif($item, now()->toDateString(), ['payer_id' => $payerId]);

        $this->assertNotNull($harga);
        $this->assertSame(
            (string) Money::tagihan((string) $dasar)->bulatkanKe(Money::SKALA_ALOKASI)
                ->tambah(Money::tagihan((string) $dasar)->persen('25'))->sebagaiTagihan(),
            (string) $harga
        );
    }

    /**
     * MARKUP BERKELAS MENGALAHKAN MARKUP UMUM. Kalau tidak, markup ICU
     * yang sengaja ditetapkan berbeda akan tertimpa baris umum yang
     * kebetulan dibuat belakangan.
     */
    #[Test]
    public function markup_berkelas_mengalahkan_markup_umum(): void
    {
        $this->penaut->sapu('pharmacy');

        $item = ChargeItem::query()->where('source_context', 'pharmacy')->firstOrFail();

        $payerId = (int) DB::table('catalog.v_payer_summary')->value('id');

        DB::table('pharmacy.drug_markups')->insert([
            [
                'payer_id' => $payerId, 'payer_code' => 'UJI', 'room_class' => null,
                'markup_percent' => '10.00', 'effective_from' => now()->subMonth()->toDateString(),
                'created_at' => now(), 'updated_at' => now(),
            ],
            [
                'payer_id' => $payerId, 'payer_code' => 'UJI', 'room_class' => 'icu',
                'markup_percent' => '40.00', 'effective_from' => now()->subYear()->toDateString(),
                'created_at' => now(), 'updated_at' => now(),
            ],
        ]);

        $dasar = Money::tagihan((string) DB::table('pharmacy.v_drug_price')
            ->where('drug_id', $item->source_id)->value('base_price'));

        $icu = $this->registry->tarif($item, now()->toDateString(), [
            'payer_id' => $payerId, 'care_class' => 'icu',
        ]);

        $diharapkan = $dasar->bulatkanKe(Money::SKALA_ALOKASI)->tambah($dasar->persen('40'))->sebagaiTagihan();

        $this->assertSame((string) $diharapkan, (string) $icu,
            'Markup ICU 40% harus menang atas baris umum 10% yang dibuat belakangan');
    }

    // --------------------------------------------------------- cakupan

    #[Test]
    public function cakupan_melaporkan_yang_belum_tertaut_per_konteks(): void
    {
        $sebelum = $this->registry->cakupan();

        $this->assertSame(0, $sebelum['catalog']['tertaut']);
        $this->assertSame(11, $sebelum['catalog']['belum']);

        $this->penaut->sapu();

        $sesudah = $this->registry->cakupan();

        $this->assertSame(11, $sesudah['catalog']['tertaut']);
        $this->assertSame(0, $sesudah['catalog']['belum']);
        $this->assertSame(0, $sesudah['inpatient']['belum']);
    }
}
