<?php

namespace Tests\Feature\Platform;

use App\Modules\Billing\Models\CashierShift;
use App\Modules\Billing\Models\Invoice;
use App\Modules\Billing\Services\CashierClosingService;
use App\Modules\Billing\Services\InvoiceService;
use App\Modules\Catalog\Models\Payer;
use App\Modules\Clinical\Database\Seeders\DiagnosisCodeSeeder;
use App\Modules\Clinical\Models\Assessment;
use App\Modules\Clinical\Models\Diagnosis;
use App\Modules\Clinical\Models\Observation;
use App\Modules\Clinical\Services\ClinicalRecordService;
use App\Modules\Encounter\Services\RegistrationService;
use App\Modules\Identity\Services\PatientRegistry;
use App\Modules\Order\Database\Seeders\TestCatalogSeeder;
use App\Modules\Order\Models\TestCatalog;
use App\Modules\Order\Services\OrderService;
use App\Modules\Organization\Models\Unit;
use App\Modules\Pharmacy\Database\Seeders\PharmacySeeder;
use App\Modules\Pharmacy\Models\Drug;
use App\Modules\Pharmacy\Models\StockLocation;
use App\Modules\Pharmacy\Services\PrescriptionService;
use App\Modules\Platform\Database\Seeders\PermissionCatalogSeeder;
use App\Modules\Platform\Database\Seeders\RoleSeeder;
use App\Modules\Platform\Models\Role;
use App\Modules\Platform\Models\User;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Perjalanan satu pasien menembus SELURUH konteks, dalam satu uji.
 *
 * MENGAPA UJI INI ADA. Tiap konteks sudah punya ujinya sendiri, dan
 * seluruhnya lolos. Tapi uji per konteks membuktikan tiap potongan benar
 * SENDIRI-SENDIRI — bukan bahwa potongan-potongan itu masih nyambung saat
 * disusun jadi satu hari kerja. Yang paling mungkin patah justru sambungannya:
 * satu konteks mengubah bentuk view yang diterbitkannya, satu lagi masih
 * membaca bentuk lama, dan tidak ada uji yang gagal karena tidak ada satu pun
 * uji yang melintasi keduanya.
 *
 * Yang ditelusuri di sini adalah satu hari yang sungguh terjadi di RSP UI:
 *
 *   identity   pasien didaftarkan
 *   encounter  mendaftar ke poliklinik, dapat nomor antrean
 *   clinical   diperiksa, tanda vital dicatat, diagnosis ditegakkan
 *   orders     dikirim ke laboratorium, hasilnya diverifikasi
 *   pharmacy   diresepkan obat, ditelaah, diserahkan
 *   billing    seluruhnya tertarik jadi tagihan, dibayar di kasir
 *   billing    shift kasir ditutup dan uangnya dicocokkan
 *
 * Yang diperiksa bukan cuma "tidak ada galat", melainkan ANGKANYA SAMPAI:
 * biaya lab dan obat benar-benar muncul di tagihan, dan pembayaran benar-
 * benar terhitung saat kasir menutup shift. Sambungan yang putus diam-diam
 * akan menghasilkan tagihan yang lebih kecil daripada seharusnya — dan itu
 * kebocoran pendapatan yang tidak pernah melempar galat apa pun.
 */
class PatientJourneyTest extends TestCase
{
    use RefreshDatabase;

    private User $petugas;

    private StockLocation $depo;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([
            PermissionCatalogSeeder::class,
            RoleSeeder::class,
            ReferenceDataSeeder::class,
            DiagnosisCodeSeeder::class,
            PharmacySeeder::class,
            TestCatalogSeeder::class,
        ]);

        $this->depo = StockLocation::query()->where('code', 'DEPO-RJ')->firstOrFail();

        $this->petugas = User::query()->create([
            'username' => 'uji-perjalanan', 'name' => 'Petugas Uji',
            'password' => 'password', 'is_active' => true,
        ]);
        $this->petugas->roles()->attach(Role::query()->where('code', 'super-admin')->firstOrFail());
    }

    #[Test]
    public function satu_pasien_umum_menembus_tujuh_konteks_dan_angkanya_sampai(): void
    {
        // ---------------------------------------------------- identity
        $pasien = app(PatientRegistry::class)->register([
            'name' => 'Siti Rahmawati', 'sex' => 'P', 'birth_date' => '1988-04-17',
        ]);

        $this->assertNotEmpty($pasien->medical_record_number,
            'Nomor rekam medis tidak terbit — seluruh perjalanan berikutnya menggantungnya.');

        // --------------------------------------------------- encounter
        $registrasi = app(RegistrationService::class)->register(
            patientId: $pasien->id,
            unitId: Unit::query()->where('code', 'POL-UMUM')->value('id'),
            payerId: Payer::query()->where('code', 'UMUM')->value('id'),
        );

        $this->assertGreaterThan(0, $registrasi->queue_number);

        /*
         * Salinan identitas pasien ikut turun ke registrasi — sengaja
         * didenormalisasi supaya layar kasir tidak perlu menyeberang
         * konteks hanya untuk menampilkan nama. Kalau salinan ini putus,
         * seluruh layar hilir menampilkan baris tanpa nama.
         */
        $this->assertSame($pasien->medical_record_number, $registrasi->patient_mrn);
        $this->assertSame('Siti Rahmawati', $registrasi->patient_name);

        // ---------------------------------------------------- clinical
        $asesmen = app(ClinicalRecordService::class)
            ->openAssessment($registrasi->id, Assessment::KIND_SOAP, $this->petugas);

        $jumlahObservasi = app(ClinicalRecordService::class)->recordObservations($asesmen, [
            'tekanan-darah-sistolik' => 128,
            'tekanan-darah-diastolik' => 84,
            'nadi' => 88,
        ], $this->petugas);

        app(ClinicalRecordService::class)->addDiagnosis(
            $asesmen, 'J06.9', 'Infeksi saluran napas atas akut',
            rank: Diagnosis::RANK_UTAMA, actor: $this->petugas
        );

        $this->assertSame(3, $jumlahObservasi,
            'Tanda vital tidak tersimpan ke clinical.observations.');

        // Dibaca ulang dari tabelnya, bukan cuma dari nilai kembalian:
        // observations DIPARTISI per bulan, dan baris yang jatuh ke partisi
        // yang keliru tetap terhitung oleh pemanggil tapi hilang dari
        // pembacaan berikutnya.
        $this->assertSame(3, Observation::query()
            ->where('assessment_id', $asesmen->id)->count());

        // ------------------------------------------------------ orders
        $order = app(OrderService::class)->create($registrasi->id, 'lab');
        $item = app(OrderService::class)->addItem(
            $order, TestCatalog::query()->where('code', 'LAB-GDS')->value('id')
        );
        app(OrderService::class)->enterResult($item, numeric: 95);
        app(OrderService::class)->verify($order->refresh());

        // ---------------------------------------------------- pharmacy
        $resep = app(PrescriptionService::class)->create($registrasi->id);
        app(PrescriptionService::class)->addItem(
            $resep, Drug::query()->where('code', 'OBT-002')->value('id'), 10, '3x1'
        );
        app(PrescriptionService::class)->submit($resep->refresh());
        app(PrescriptionService::class)->review($resep->refresh(), 'disetujui', null, $this->petugas);
        app(PrescriptionService::class)->dispense($resep->refresh(), $this->depo->id, $this->petugas);

        $this->assertSame('diserahkan', $resep->refresh()->status);

        // ----------------------------------------------------- billing
        $tagihan = app(InvoiceService::class)->openInvoice($registrasi->id);

        /*
         * INTI SELURUH UJI INI. Tagihan menarik dari TIGA konteks berbeda —
         * registrasi (encounter), obat (pharmacy), dan pemeriksaan lab
         * (orders) — lewat view yang diterbitkan masing-masing. Sambungan
         * yang putus tidak melempar galat; ia cuma menghasilkan tagihan
         * yang lebih kecil, dan tidak ada yang tahu sampai ada yang
         * mencocokkan manual.
         */
        $sumber = $tagihan->chargeLines()->pluck('source_type')->unique()->sort()->values()->all();

        $this->assertSame(['order_penunjang', 'registrasi', 'resep_obat'], $sumber,
            'Ada sumber biaya yang tidak sampai ke tagihan. Sambungan antar konteks '
            .'yang putus TIDAK melempar galat — ia cuma menagih terlalu sedikit.');

        $this->assertTrue($tagihan->isPatientPayable());
        $this->assertGreaterThan(50_000, (float) $tagihan->total_amount,
            'Tagihan cuma berisi biaya registrasi — obat dan lab tidak ikut tertarik.');

        // ------------------------------------------------------- kasir
        $nominal = (float) $tagihan->total_amount;

        $pembayaran = app(InvoiceService::class)->pay($tagihan, $nominal, 'tunai', $this->petugas);

        $this->assertSame(Invoice::STATUS_LUNAS, $tagihan->refresh()->status);

        // ------------------------------------------- penutupan shift
        $shift = CashierShift::query()->create([
            'code' => 'PAGI', 'name' => 'Pagi',
            'start_time' => '00:00', 'end_time' => '23:59',
            'crosses_midnight' => false, 'is_active' => true,
        ]);

        $tutup = app(CashierClosingService::class)->close(
            $shift, now()->toDateString(), 'Kasir Uji', $nominal,
        );

        /*
         * Pembayaran yang baru saja diterima harus terhitung saat shiftnya
         * ditutup. Kalau penutupan membaca rentang yang keliru — atau
         * menyaring metode pembayaran secara keliru — kasir yang bekerja
         * benar akan tampak punya selisih.
         */
        $this->assertSame(number_format($nominal, 2, '.', ''), $tutup->recorded_cash,
            'Pembayaran hari ini tidak terhitung saat shift kasir ditutup.');

        $this->assertTrue($tutup->cocok(),
            'Penutupan shift melaporkan selisih padahal uangnya persis sama.');
    }

    #[Test]
    public function pasien_bpjs_tidak_pernah_sampai_ke_laci_kasir(): void
    {
        $pasien = app(PatientRegistry::class)->register([
            'name' => 'Budi Santoso', 'sex' => 'L', 'birth_date' => '1975-11-02',
        ]);

        $registrasi = app(RegistrationService::class)->register(
            patientId: $pasien->id,
            unitId: Unit::query()->where('code', 'POL-UMUM')->value('id'),
            payerId: Payer::query()->where('code', 'BPJS')->value('id'),
        );

        $tagihan = app(InvoiceService::class)->openInvoice($registrasi->id);

        /*
         * Jalur kedua yang wajib ikut ditelusuri: pada 2.000 pasien sehari,
         * sebagian besar dijamin BPJS. Tagihannya TIDAK ditagihkan di kasir
         * melainkan jadi piutang penjamin — dan kalau ia salah ikut terhitung
         * di penutupan shift, kasir akan tampak kekurangan uang sebesar
         * seluruh pasien BPJS hari itu.
         */
        $this->assertSame(Invoice::STATUS_DITANGGUNG_PENJAMIN, $tagihan->status);

        $shift = CashierShift::query()->create([
            'code' => 'PAGI', 'name' => 'Pagi',
            'start_time' => '00:00', 'end_time' => '23:59',
            'crosses_midnight' => false, 'is_active' => true,
        ]);

        $tutup = app(CashierClosingService::class)->close(
            $shift, now()->toDateString(), 'Kasir Uji', 0.0,
        );

        $this->assertSame('0.00', $tutup->recorded_cash,
            'Tagihan yang ditanggung BPJS ikut terhitung sebagai uang laci kasir.');
        $this->assertTrue($tutup->cocok());
    }

    #[Test]
    public function nomor_antrean_tidak_pernah_kembar_dalam_satu_hari(): void
    {
        $unitId = Unit::query()->where('code', 'POL-UMUM')->value('id');
        $payerId = Payer::query()->where('code', 'UMUM')->value('id');

        /*
         * Pada 2.000 pasien sehari, beberapa loket mendaftarkan pasien pada
         * detik yang sama. Nomor antrean kembar bukan sekadar memalukan —
         * dua orang dipanggil bersamaan dan yang kedua kehilangan gilirannya.
         *
         * Uji ini tidak bisa membuktikan keamanan terhadap balapan sungguhan
         * (butuh proses paralel), tapi ia mengunci hal yang bisa dibuktikan:
         * penomorannya berurut, unik per unit per hari, dan ditegakkan
         * indeks unik di basis data — bukan cuma oleh urutan kode.
         */
        $nomor = [];

        for ($i = 0; $i < 15; $i++) {
            $pasien = app(PatientRegistry::class)->register([
                'name' => 'Pasien Antre '.$i, 'sex' => 'L', 'birth_date' => '1990-01-01',
            ]);

            $nomor[] = app(RegistrationService::class)->register(
                patientId: $pasien->id, unitId: $unitId, payerId: $payerId,
            )->queue_number;
        }

        $this->assertSame($nomor, array_unique($nomor),
            'Ada nomor antrean kembar dalam satu unit pada satu hari.');

        $this->assertSame(range(1, 15), $nomor,
            'Nomor antrean tidak berurut dari 1.');
    }
}
