<?php

namespace Tests\Feature\Order;

use App\Modules\Catalog\Models\Payer;
use App\Modules\Encounter\Models\Registration;
use App\Modules\Encounter\Services\RegistrationService;
use App\Modules\Identity\Services\PatientRegistry;
use App\Modules\Order\Database\Seeders\TestCatalogSeeder;
use App\Modules\Order\Models\LabRadiologyOrder;
use App\Modules\Order\Models\TestCatalog;
use App\Modules\Order\Services\OrderException;
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

class OrderTest extends TestCase
{
    use RefreshDatabase;

    private OrderService $orders;
    private User $petugasLab;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([
            PermissionCatalogSeeder::class,
            RoleSeeder::class,
            ReferenceDataSeeder::class,
            TestCatalogSeeder::class,
        ]);

        $this->orders = app(OrderService::class);

        $this->petugasLab = User::query()->create([
            'username' => 'uji-lab', 'name' => 'Petugas Lab Uji', 'password' => 'password', 'is_active' => true,
        ]);
        $this->petugasLab->roles()->attach(Role::query()->where('code', 'petugas-lab')->firstOrFail());
    }

    #[Test]
    public function order_lab_dibuka_kosong_dan_mewarisi_konteks_kunjungan(): void
    {
        $registrasi = $this->daftarkan('Warsito');

        $order = $this->orders->create($registrasi->id, 'lab');

        $this->assertSame($registrasi->patient_id, $order->patient_id);
        $this->assertSame('lab', $order->category);
        $this->assertSame(LabRadiologyOrder::STATUS_DIMINTA, $order->status);
        $this->assertSame(0, $order->items()->count());
        $this->assertStringStartsWith('LAB' . now()->format('Ymd'), $order->order_number);
    }

    #[Test]
    public function membuka_order_kosong_dua_kali_melanjutkan_yang_sama(): void
    {
        $registrasi = $this->daftarkan();

        $a = $this->orders->create($registrasi->id, 'lab');
        $b = $this->orders->create($registrasi->id, 'lab');

        $this->assertSame($a->id, $b->id);
        $this->assertSame(1, LabRadiologyOrder::query()->count());
    }

    #[Test]
    public function order_dengan_pemeriksaan_eksplisit_selalu_membuat_order_baru(): void
    {
        // Berbeda dari panggilan kosong: permintaan susulan (add-on test)
        // harus tetap tercatat sebagai order tersendiri.
        $registrasi = $this->daftarkan();
        $hb = TestCatalog::query()->where('code', 'LAB-HB')->value('id');

        $a = $this->orders->create($registrasi->id, 'lab', [$hb]);
        $b = $this->orders->create($registrasi->id, 'lab', [$hb]);

        $this->assertNotSame($a->id, $b->id);
        $this->assertSame(2, LabRadiologyOrder::query()->count());
    }

    #[Test]
    public function pemeriksaan_kategori_salah_ditolak(): void
    {
        $registrasi = $this->daftarkan();
        $rontgen = TestCatalog::query()->where('code', 'RAD-THX')->value('id');

        $this->expectException(OrderException::class);
        $this->expectExceptionMessage('bukan pemeriksaan lab');

        $this->orders->create($registrasi->id, 'lab', [$rontgen]);
    }

    #[Test]
    public function menambah_pemeriksaan_menyalin_rentang_rujukan_dan_harga_saat_itu(): void
    {
        $order = $this->orders->create($this->daftarkan()->id, 'lab');
        $hb = TestCatalog::query()->where('code', 'LAB-HB')->firstOrFail();

        $item = $this->orders->addItem($order, $hb->id);

        $this->assertSame('12.00', $item->reference_low);
        $this->assertSame('16.00', $item->reference_high);
        $this->assertSame((string) $hb->price, (string) $item->unit_price);

        // Katalog berubah kemudian; item yang sudah tercatat tidak ikut berubah.
        $hargaLama = $hb->price;
        $hb->update(['reference_low' => 13, 'price' => 99999]);
        $this->assertSame('12.00', $item->refresh()->reference_low);
        $this->assertSame((string) $hargaLama, $item->unit_price);
    }

    #[Test]
    public function pemeriksaan_yang_sama_tidak_bisa_ditambah_dua_kali(): void
    {
        $order = $this->orders->create($this->daftarkan()->id, 'lab');
        $hb = TestCatalog::query()->where('code', 'LAB-HB')->value('id');
        $this->orders->addItem($order, $hb);

        $this->expectException(OrderException::class);
        $this->expectExceptionMessage('sudah ada di order ini');

        $this->orders->addItem($order, $hb);
    }

    #[Test]
    public function order_kosong_tidak_bisa_mulai_diproses(): void
    {
        $order = $this->orders->create($this->daftarkan()->id, 'lab');

        $this->expectException(OrderException::class);
        $this->expectExceptionMessage('belum memiliki pemeriksaan');

        $this->orders->startProcessing($order);
    }

    #[Test]
    public function hasil_kuantitatif_di_luar_rentang_ditandai_abnormal(): void
    {
        $order = $this->orders->create($this->daftarkan()->id, 'lab');
        $item = $this->orders->addItem($order, TestCatalog::query()->where('code', 'LAB-HB')->value('id'));

        $normal = $this->orders->enterResult($item, numeric: 14.0);
        $this->assertFalse($normal->is_abnormal);

        $rendah = $this->orders->enterResult($item->refresh(), numeric: 8.0);
        $this->assertTrue($rendah->is_abnormal);
    }

    #[Test]
    public function hasil_kualitatif_yang_menyimpang_dari_rujukan_ditandai_abnormal(): void
    {
        $order = $this->orders->create($this->daftarkan()->id, 'lab');
        $item = $this->orders->addItem($order, TestCatalog::query()->where('code', 'LAB-HBSAG')->value('id'));

        $negatif = $this->orders->enterResult($item, text: 'Negatif');
        $this->assertFalse($negatif->is_abnormal);

        $positif = $this->orders->enterResult($item->refresh(), text: 'Positif');
        $this->assertTrue($positif->is_abnormal);
    }

    #[Test]
    public function hasil_naratif_tidak_pernah_otomatis_ditandai_abnormal(): void
    {
        $order = $this->orders->create($this->daftarkan()->id, 'radiologi');
        $item = $this->orders->addItem($order, TestCatalog::query()->where('code', 'RAD-THX')->value('id'));

        $hasil = $this->orders->enterResult($item, notes: 'Cor dan pulmo dalam batas normal.');

        $this->assertFalse($hasil->is_abnormal);
        $this->assertSame('Cor dan pulmo dalam batas normal.', $hasil->result_notes);
    }

    #[Test]
    public function order_pindah_hasil_tersedia_otomatis_begitu_semua_item_terisi(): void
    {
        $order = $this->orders->create($this->daftarkan()->id, 'lab');
        $hb = $this->orders->addItem($order, TestCatalog::query()->where('code', 'LAB-HB')->value('id'));
        $leu = $this->orders->addItem($order->refresh(), TestCatalog::query()->where('code', 'LAB-LEU')->value('id'));

        $this->orders->enterResult($hb, numeric: 14);
        $this->assertSame(LabRadiologyOrder::STATUS_DIPROSES, $order->refresh()->status);

        $this->orders->enterResult($leu, numeric: 7);
        $this->assertSame(LabRadiologyOrder::STATUS_HASIL_TERSEDIA, $order->refresh()->status);
    }

    #[Test]
    public function order_tidak_bisa_diverifikasi_sebelum_hasil_lengkap(): void
    {
        $order = $this->orders->create($this->daftarkan()->id, 'lab');
        $this->orders->addItem($order, TestCatalog::query()->where('code', 'LAB-HB')->value('id'));

        $this->expectException(OrderException::class);
        $this->expectExceptionMessage('hasil lengkap');

        $this->orders->verify($order->refresh(), $this->petugasLab);
    }

    #[Test]
    public function verifikasi_mengunci_order_menjadi_selesai(): void
    {
        $order = $this->orders->create($this->daftarkan()->id, 'lab');
        $item = $this->orders->addItem($order, TestCatalog::query()->where('code', 'LAB-HB')->value('id'));
        $this->orders->enterResult($item, numeric: 14);

        $selesai = $this->orders->verify($order->refresh(), $this->petugasLab);

        $this->assertSame(LabRadiologyOrder::STATUS_SELESAI, $selesai->status);
        $this->assertSame($this->petugasLab->id, $selesai->verified_by);
        $this->assertNotNull($selesai->verified_at);
    }

    #[Test]
    public function order_yang_sudah_selesai_tidak_bisa_dibatalkan(): void
    {
        $order = $this->orders->create($this->daftarkan()->id, 'lab');
        $item = $this->orders->addItem($order, TestCatalog::query()->where('code', 'LAB-HB')->value('id'));
        $this->orders->enterResult($item, numeric: 14);
        $selesai = $this->orders->verify($order->refresh(), $this->petugasLab);

        $this->expectException(OrderException::class);
        $this->expectExceptionMessage('tidak bisa dibatalkan');

        $this->orders->cancel($selesai, 'salah input');
    }

    #[Test]
    public function order_yang_selesai_terbaca_lewat_view_terbitan_untuk_billing(): void
    {
        $order = $this->orders->create($this->daftarkan()->id, 'lab');
        $item = $this->orders->addItem($order, TestCatalog::query()->where('code', 'LAB-GDS')->value('id'));
        $this->orders->enterResult($item, numeric: 95);
        $this->orders->verify($order->refresh(), $this->petugasLab);

        $baris = DB::table('orders.v_order_charge')
            ->where('registration_id', $order->registration_id)
            ->first();

        $this->assertNotNull($baris);
        $this->assertSame('Glukosa Darah Sewaktu', $baris->test_name);
        $this->assertSame('30000.00', $baris->amount);
    }

    #[Test]
    public function order_yang_belum_selesai_tidak_muncul_di_view_tagihan(): void
    {
        $order = $this->orders->create($this->daftarkan()->id, 'lab');
        $this->orders->addItem($order, TestCatalog::query()->where('code', 'LAB-GDS')->value('id'));

        $ada = DB::table('orders.v_order_charge')->where('registration_id', $order->registration_id)->exists();

        $this->assertFalse($ada);
    }

    #[Test]
    public function basis_data_menolak_pemeriksaan_ganda_dalam_satu_order_walau_kode_lolos(): void
    {
        $order = $this->orders->create($this->daftarkan()->id, 'lab');
        $hb = TestCatalog::query()->where('code', 'LAB-HB')->value('id');
        $this->orders->addItem($order, $hb);

        $this->expectException(QueryException::class);

        DB::table('orders.order_items')->insert([
            'order_id' => $order->id, 'test_id' => $hb,
            'test_code' => 'LAB-HB', 'test_name' => 'Hemoglobin', 'result_type' => 'kuantitatif',
            'unit_price' => 35000, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    // ------------------------------------------------------------------ bantu

    private function daftarkan(string $nama = 'Pasien Order'): Registration
    {
        static $urut = 0;
        $urut++;

        $pasien = app(PatientRegistry::class)->register([
            'name' => $nama . ' ' . $urut, 'sex' => 'L', 'birth_date' => '1990-01-01',
        ]);

        return app(RegistrationService::class)->register(
            patientId: $pasien->id,
            unitId: Unit::query()->where('code', 'POL-UMUM')->value('id'),
            payerId: Payer::query()->where('code', 'UMUM')->value('id'),
        );
    }
}
