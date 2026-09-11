<?php

namespace Tests\Feature\Reporting;

use App\Modules\Catalog\Models\Payer;
use App\Modules\Encounter\Models\Registration;
use App\Modules\Encounter\Services\RegistrationService;
use App\Modules\Identity\Services\PatientRegistry;
use App\Modules\Inpatient\Database\Seeders\InpatientSeeder;
use App\Modules\Inpatient\Models\Bed;
use App\Modules\Inpatient\Services\AdmissionService;
use App\Modules\Organization\Models\Practitioner;
use App\Modules\Organization\Models\Unit;
use App\Modules\Platform\Database\Seeders\PermissionCatalogSeeder;
use App\Modules\Platform\Database\Seeders\RoleSeeder;
use App\Modules\Platform\Models\Role;
use App\Modules\Platform\Models\User;
use App\Modules\Reporting\Services\AncillaryReportService;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Domain J item E: penunjang, gizi, skrining & sasaran usia.
 *
 * Dua hal yang paling perlu dikunci: porsi gizi dihitung sebagai HARI-DIET
 * (bukan jumlah permintaan, yang akan membuat angkanya jauh terlalu kecil),
 * dan data yang tidak lengkap tetap dihitung dengan label sendiri alih-alih
 * dibuang sampai laporan tampak rapi.
 */
class AncillaryReportTest extends TestCase
{
    use RefreshDatabase;

    private AncillaryReportService $penunjang;

    private RegistrationService $registrations;

    private User $manajemen;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([
            PermissionCatalogSeeder::class, RoleSeeder::class,
            ReferenceDataSeeder::class, InpatientSeeder::class,
        ]);

        $this->penunjang = app(AncillaryReportService::class);
        $this->registrations = app(RegistrationService::class);

        $this->manajemen = User::query()->create([
            'username' => 'uji-manajemen-penunjang', 'name' => 'Manajemen', 'password' => 'password', 'is_active' => true,
        ]);
        $this->manajemen->roles()->attach(Role::query()->where('code', 'manajemen')->firstOrFail());
    }

    // ------------------------------------------------------------- penunjang

    #[Test]
    public function rekap_penunjang_dipisahkan_menurut_kategori(): void
    {
        $r = $this->daftarkan();
        $this->order($r, 'lab');
        $this->order($r, 'lab');
        $this->order($r, 'radiologi');

        $tahun = (int) now()->year;

        $this->assertSame(2, (int) $this->penunjang->ancillaryYearly('lab', $tahun)->sum('permintaan'));
        $this->assertSame(1, (int) $this->penunjang->ancillaryYearly('radiologi', $tahun)->sum('permintaan'));
    }

    /** Permintaan tanpa perujuk tetap dihitung, supaya totalnya cocok dengan rekap tahunan. */
    #[Test]
    public function perujuk_yang_tidak_tercatat_tetap_dihitung(): void
    {
        $r = $this->daftarkan();
        $this->order($r, 'lab', 'dr. Uji');
        $this->order($r, 'lab', null);

        $tahun = (int) now()->year;
        $perujuk = $this->penunjang->ancillaryReferrers('lab', $tahun)->pluck('permintaan', 'perujuk');

        $this->assertSame(1, (int) $perujuk['dr. Uji']);
        $this->assertSame(1, (int) $perujuk['(tidak tercatat)'], 'Bukan dibuang');
        $this->assertSame(
            (int) $this->penunjang->ancillaryYearly('lab', $tahun)->sum('permintaan'),
            (int) $perujuk->sum(),
            'Total perujuk harus cocok dengan rekap tahunan'
        );
    }

    // ------------------------------------------------------------------ gizi

    /**
     * Inti gizi: satu permintaan diet lima hari adalah LIMA hari-diet,
     * bukan satu. Menghitungnya sebagai satu membuat angka gizi jauh
     * lebih kecil daripada kenyataannya.
     */
    #[Test]
    public function porsi_gizi_dihitung_sebagai_hari_diet_bukan_jumlah_permintaan(): void
    {
        $admisi = $this->admisi();

        DB::table('inpatient.diet_orders')->insert([
            'admission_id' => $admisi,
            'diet_type' => 'biasa',
            'status' => 'aktif',
            'start_date' => now()->subDays(4)->toDateString(),
            'end_date' => now()->toDateString(),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $baris = $this->penunjang->dietRecap(now()->subDays(7)->toDateString(), now()->toDateString())->first();

        $this->assertSame(1, (int) $baris->permintaan);
        $this->assertSame(5, (int) $baris->hari_diet, 'Lima hari pemberian, bukan satu permintaan');
    }

    /** Diet yang melintasi batas rentang dihitung hanya sepanjang irisannya. */
    #[Test]
    public function diet_yang_melintasi_batas_rentang_dihitung_sepanjang_irisannya(): void
    {
        $admisi = $this->admisi();

        DB::table('inpatient.diet_orders')->insert([
            'admission_id' => $admisi,
            'diet_type' => 'lunak',
            'status' => 'aktif',
            'start_date' => now()->subDays(9)->toDateString(),
            'end_date' => now()->toDateString(),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $baris = $this->penunjang->dietRecap(now()->subDays(2)->toDateString(), now()->toDateString())->first();

        $this->assertSame(3, (int) $baris->hari_diet, 'Hanya tiga hari yang jatuh di dalam rentang');
    }

    /** Diet yang dihentikan TETAP sempat diberikan — tidak boleh dibuang. */
    #[Test]
    public function diet_yang_dihentikan_tetap_dihitung(): void
    {
        $admisi = $this->admisi();

        DB::table('inpatient.diet_orders')->insert([
            'admission_id' => $admisi,
            'diet_type' => 'cair',
            'status' => 'dihentikan',
            'start_date' => now()->subDays(2)->toDateString(),
            'end_date' => now()->toDateString(),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $baris = $this->penunjang->dietRecap(now()->subDays(7)->toDateString(), now()->toDateString());

        $this->assertCount(1, $baris, 'Diet yang dihentikan sudah terlanjur dimasak');
        $this->assertSame(3, (int) $baris->first()->hari_diet);
    }

    // ----------------------------------------------- klasifikasi & sasaran

    /** Pasien yang belum diklasifikasi diberi label sendiri, bukan dibuang. */
    #[Test]
    public function pasien_belum_diklasifikasi_tetap_muncul(): void
    {
        $this->admisi();

        $baris = $this->penunjang->inpatientClass(
            'bulanan', now()->subDays(7)->toDateString(), now()->toDateString()
        );

        $this->assertCount(1, $baris);
        $this->assertSame('(belum diklasifikasi)', $baris->first()->klasifikasi);
    }

    #[Test]
    public function klasifikasi_bisa_dikelompokkan_per_bangsal(): void
    {
        $this->admisi();

        $baris = $this->penunjang->inpatientClass(
            'bangsal', now()->subDays(7)->toDateString(), now()->toDateString()
        );

        $this->assertCount(1, $baris);
        $this->assertSame('(bangsal belum ditautkan)', $baris->first()->kelompok,
            'Kamar seeder belum tertaut unit organisasi — diberi label, bukan NULL');
    }

    /**
     * REKAP BULAN LALU TIDAK BERUBAH SAAT PASIENNYA DIKATEGORIKAN ULANG.
     *
     * DITEMUKAN SAAT VERIFIKASI DOMAIN M. Sampai saat itu method ini
     * mengelompokkan menurut identity.patients.inpatient_classification —
     * atribut pasien yang bisa diubah kapan saja lewat layar pasien.
     * Akibatnya rekap klasifikasi bulan lalu bergeser setiap kali seorang
     * pasien dikategorikan ulang, dan TIDAK ADA YANG TERLIHAT SALAH: tidak
     * ada baris yang hilang, totalnya tetap cocok, cuma pembagiannya
     * berubah — dan tidak ada yang menghafal pembagian bulan lalu.
     *
     * Uji ini menyalakan lampunya: ia gagal pada kode lama dan lulus pada
     * kode yang membekukan kategorinya di admisi.
     */
    #[Test]
    public function klasifikasi_dibekukan_saat_masuk_bukan_dibaca_ulang(): void
    {
        $registrasi = $this->daftarkan('ranap');

        DB::table('identity.patients')->where('id', $registrasi->patient_id)
            ->update(['inpatient_classification' => 'Umum']);

        $admisi = app(AdmissionService::class)->admit(
            $registrasi->id,
            Bed::query()->firstOrFail(),
        );

        DB::table('inpatient.admissions')->where('id', $admisi->id)
            ->update(['admitted_at' => now()->subDays(4)]);

        $rentang = [now()->subDays(7)->toDateString(), now()->toDateString()];

        $this->assertSame('Umum',
            $this->penunjang->inpatientClass('bulanan', ...$rentang)->first()->klasifikasi);

        // Pasien yang sama dikategorikan ulang setelah dirawat.
        DB::table('identity.patients')->where('id', $registrasi->patient_id)
            ->update(['inpatient_classification' => 'VIP']);

        $this->assertSame('Umum',
            $this->penunjang->inpatientClass('bulanan', ...$rentang)->first()->klasifikasi,
            'Rekap klasifikasi yang sudah lewat tidak boleh ikut berubah');
    }

    /** Umur dihitung pada tanggal kunjungan, bukan hari ini. */
    #[Test]
    public function sasaran_usia_dipisahkan_produktif_dan_lansia(): void
    {
        $this->daftarkanUsia(30);   // produktif
        $this->daftarkanUsia(70);   // lansia
        $this->daftarkanUsia(8);    // di luar sasaran

        $sasaran = $this->penunjang
            ->ageTargets(now()->toDateString(), now()->toDateString())
            ->pluck('kunjungan', 'sasaran');

        $this->assertSame(1, (int) $sasaran['usia-produktif']);
        $this->assertSame(1, (int) $sasaran['lansia']);
        $this->assertSame(1, (int) $sasaran['di luar sasaran']);
    }

    // ------------------------------------------------------------------ layar

    #[Test]
    public function layar_penunjang_hanya_untuk_yang_berhak(): void
    {
        $this->actingAs($this->manajemen)->get(route('reporting.penunjang'))->assertOk();

        $kasir = User::query()->create([
            'username' => 'uji-kasir-penunjang', 'name' => 'Kasir', 'password' => 'password', 'is_active' => true,
        ]);
        $kasir->roles()->attach(Role::query()->where('code', 'kasir')->firstOrFail());

        $this->actingAs($kasir)->get(route('reporting.penunjang'))->assertForbidden();
    }

    #[Test]
    public function layar_penunjang_menyatakan_yang_belum_bisa_dilaporkan(): void
    {
        $this->actingAs($this->manajemen)
            ->get(route('reporting.penunjang'))
            ->assertOk()
            ->assertSee('Dosis Radiologi', false)
            ->assertSee('Kepatuhan Kelengkapan Keselamatan Bedah', false)
            ->assertSee('Sisa Diet Pasien', false)
            ->assertSee('hari-diet', false);
    }

    // ------------------------------------------------------------------ bantu

    private function daftarkan(string $careType = 'ralan', string $lahir = '1990-01-01'): Registration
    {
        static $urut = 0;
        $urut++;

        $pasien = app(PatientRegistry::class)->register([
            'name' => 'Pasien Penunjang '.$urut, 'sex' => 'L', 'birth_date' => $lahir,
        ]);

        return $this->registrations->register(
            patientId: $pasien->id,
            unitId: Unit::query()->where('code', 'POL-UMUM')->firstOrFail()->id,
            payerId: Payer::query()->where('code', 'UMUM')->value('id'),
            practitionerId: Practitioner::query()->where('is_active', true)->value('id'),
            extra: $careType === 'ranap' ? ['care_type' => 'ranap'] : [],
        );
    }

    private function daftarkanUsia(int $umur): void
    {
        $this->daftarkan('ralan', now()->subYears($umur)->subMonths(1)->toDateString());
    }

    private function order(Registration $r, string $kategori, ?string $perujuk = 'dr. Uji'): void
    {
        static $urut = 0;
        $urut++;

        DB::table('orders.orders')->insert([
            'order_number' => strtoupper($kategori).'-'.$urut,
            'registration_id' => $r->id,
            'patient_id' => $r->patient_id,
            'registration_number' => $r->registration_number,
            'patient_mrn' => 'RM-UJI',
            'patient_name' => 'Pasien Uji',
            'category' => $kategori,
            'status' => 'diminta',
            'requesting_practitioner_name' => $perujuk,
            'requested_at' => now(),
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function admisi(): int
    {
        $admisi = app(AdmissionService::class)->admit(
            $this->daftarkan('ranap')->id,
            Bed::query()->firstOrFail(),
        );

        DB::table('inpatient.admissions')->where('id', $admisi->id)
            ->update(['admitted_at' => now()->subDays(4)]);

        return $admisi->id;
    }
}
