<?php

namespace Tests\Feature\Reporting;

use App\Modules\Catalog\Models\Payer;
use App\Modules\Clinical\Database\Seeders\DiagnosisCodeSeeder;
use App\Modules\Encounter\Models\Registration;
use App\Modules\Encounter\Services\RegistrationService;
use App\Modules\Identity\Services\PatientRegistry;
use App\Modules\Organization\Models\Practitioner;
use App\Modules\Organization\Models\Unit;
use App\Modules\Platform\Database\Seeders\PermissionCatalogSeeder;
use App\Modules\Platform\Database\Seeders\RoleSeeder;
use App\Modules\Platform\Models\Role;
use App\Modules\Platform\Models\User;
use App\Modules\Reporting\Services\MorbidityReportService;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Domain J item B: morbiditas & surveilans penyakit.
 *
 * Klasifikasinya dua lapis: sifat penularan sebagai kolom pada kamus
 * penyakit, dan keanggotaan program surveilans sebagai tabel tersendiri
 * karena satu penyakit bisa masuk beberapa program sekaligus.
 */
class MorbidityReportTest extends TestCase
{
    use RefreshDatabase;

    private MorbidityReportService $morbiditas;
    private RegistrationService $registrations;
    private User $manajemen;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([PermissionCatalogSeeder::class, RoleSeeder::class, ReferenceDataSeeder::class, DiagnosisCodeSeeder::class]);

        $this->morbiditas = app(MorbidityReportService::class);
        $this->registrations = app(RegistrationService::class);

        $this->manajemen = User::query()->create([
            'username' => 'uji-manajemen-morbid', 'name' => 'Manajemen Uji', 'password' => 'password', 'is_active' => true,
        ]);
        $this->manajemen->roles()->attach(Role::query()->where('code', 'manajemen')->firstOrFail());
    }

    #[Test]
    public function penyakit_dipisahkan_menular_dan_tidak_menular(): void
    {
        $this->diagnosa('J06.9');   // ISPA — menular
        $this->diagnosa('J06.9');
        $this->diagnosa('I10');     // hipertensi — tidak menular

        $hariIni = now()->toDateString();
        $rekap = $this->morbiditas->byTransmission($hariIni, $hariIni)->keyBy('transmission');

        $this->assertSame(2, (int) $rekap['menular']->jumlah);
        $this->assertSame(1, (int) $rekap['tidak-menular']->jumlah);
    }

    /**
     * Kode di luar kamus tetap dihitung. Membuangnya akan membuat laporan
     * tampak rapi padahal ada kasus yang hilang.
     */
    #[Test]
    public function diagnosis_di_luar_kamus_tetap_dihitung_sebagai_tidak_diketahui(): void
    {
        $this->diagnosa('J06.9');
        $this->diagnosaMentah('Z99.9', 'Kode di luar kamus');

        $hariIni = now()->toDateString();
        $rekap = $this->morbiditas->byTransmission($hariIni, $hariIni)->keyBy('transmission');

        $this->assertSame(1, (int) $rekap['menular']->jumlah);
        $this->assertSame(1, (int) $rekap['tidak-diketahui']->jumlah, 'Kasusnya tidak boleh hilang');
    }

    #[Test]
    public function frekuensi_penyakit_diurutkan_dari_yang_terbanyak(): void
    {
        $this->diagnosa('J06.9');
        $this->diagnosa('J06.9');
        $this->diagnosa('J06.9');
        $this->diagnosa('I10');

        $hariIni = now()->toDateString();
        $frekuensi = $this->morbiditas->frequency($hariIni, $hariIni);

        $this->assertSame('J06.9', $frekuensi->first()->code);
        $this->assertSame(3, (int) $frekuensi->first()->jumlah);
    }

    /**
     * Inti keputusan bentuk data: satu penyakit boleh masuk beberapa
     * program surveilans, dan itu TIDAK boleh membuatnya terhitung
     * berkali-kali.
     */
    #[Test]
    public function penyakit_di_dua_program_surveilans_tidak_terhitung_ganda(): void
    {
        DB::table('clinical.diagnosis_surveillance_groups')->insert([
            ['code' => 'J06.9', 'group' => 'pd3i', 'created_at' => now(), 'updated_at' => now()],
            ['code' => 'J06.9', 'group' => 'tb-sitt', 'created_at' => now(), 'updated_at' => now()],
        ]);

        $this->diagnosa('J06.9');
        $this->diagnosa('J06.9');

        $hariIni = now()->toDateString();

        $pd3i = $this->morbiditas->bySurveillanceGroup('pd3i', $hariIni, $hariIni);
        $tb = $this->morbiditas->bySurveillanceGroup('tb-sitt', $hariIni, $hariIni);

        $this->assertCount(1, $pd3i, 'Satu baris penyakit, bukan tergandakan per program');
        $this->assertSame(2, (int) $pd3i->first()->jumlah);
        $this->assertSame(2, (int) $tb->first()->jumlah, 'Program lain melihat kasus yang sama');
    }

    #[Test]
    public function program_surveilans_hanya_menghitung_anggotanya(): void
    {
        DB::table('clinical.diagnosis_surveillance_groups')->insert([
            ['code' => 'J06.9', 'group' => 'pd3i', 'created_at' => now(), 'updated_at' => now()],
        ]);

        $this->diagnosa('J06.9');
        $this->diagnosa('I10');   // bukan anggota

        $hariIni = now()->toDateString();
        $pd3i = $this->morbiditas->bySurveillanceGroup('pd3i', $hariIni, $hariIni);

        $this->assertCount(1, $pd3i);
        $this->assertSame('J06.9', $pd3i->first()->code);
    }

    #[Test]
    public function daftar_program_hanya_menampilkan_yang_punya_anggota(): void
    {
        $this->assertCount(0, $this->morbiditas->surveillanceGroups(), 'Seeder sengaja tidak menebak keanggotaan');

        DB::table('clinical.diagnosis_surveillance_groups')->insert([
            ['code' => 'J06.9', 'group' => 'pd3i', 'created_at' => now(), 'updated_at' => now()],
        ]);

        $daftar = $this->morbiditas->surveillanceGroups();
        $this->assertCount(1, $daftar);
        $this->assertSame('pd3i', $daftar->first()->group);
    }

    #[Test]
    public function penyaring_jenis_rawat_berlaku_pada_seluruh_potongan(): void
    {
        $this->diagnosa('J06.9');
        $this->diagnosa('J06.9', careType: 'ranap');

        $hariIni = now()->toDateString();

        foreach (['frequency', 'byTransmission'] as $metode) {
            $semua = $this->morbiditas->{$metode}($hariIni, $hariIni);
            $ralan = $this->morbiditas->{$metode}($hariIni, $hariIni, 'ralan');

            $this->assertSame(2, (int) $semua->sum('jumlah'), "{$metode} tanpa penyaring");
            $this->assertSame(1, (int) $ralan->sum('jumlah'), "{$metode} harus menghormati penyaring jenis rawat");
        }

        $this->assertSame(1, (int) $this->morbiditas->detailByTransmission('menular', $hariIni, $hariIni, 'ranap')->sum('jumlah'));
    }

    #[Test]
    public function morbiditas_bisa_dipilah_per_cara_bayar(): void
    {
        $this->diagnosa('J06.9');
        $this->diagnosa('I10', penjamin: 'BPJS');

        $hariIni = now()->toDateString();
        $perPenjamin = $this->morbiditas->byPayer($hariIni, $hariIni)->pluck('jumlah', 'payer_name');

        $this->assertSame(1, (int) $perPenjamin['Umum / Bayar Sendiri']);
        $this->assertSame(1, (int) $perPenjamin['BPJS Kesehatan']);
    }

    #[Test]
    public function layar_morbiditas_hanya_untuk_yang_berhak(): void
    {
        $this->actingAs($this->manajemen)->get(route('reporting.morbiditas'))->assertOk();

        $kasir = User::query()->create([
            'username' => 'uji-kasir-morbid', 'name' => 'Kasir Uji', 'password' => 'password', 'is_active' => true,
        ]);
        $kasir->roles()->attach(Role::query()->where('code', 'kasir')->firstOrFail());

        $this->actingAs($kasir)->get(route('reporting.morbiditas'))->assertForbidden();
    }

    #[Test]
    public function layar_morbiditas_menerima_penyaring_lewat_http(): void
    {
        $this->diagnosa('J06.9');

        $this->actingAs($this->manajemen)
            ->get(route('reporting.morbiditas', [
                'dari' => now()->toDateString(),
                'sampai' => now()->toDateString(),
                'penularan' => 'menular',
            ]))
            ->assertOk()
            ->assertSee('J06.9');
    }

    // ------------------------------------------------------------------ bantu

    /**
     * Diagnosis disisipkan langsung, tanpa melewati alur asesmen klinis.
     *
     * Yang diuji di sini laporannya, bukan pencatatan klinisnya — itu
     * sudah punya pengujiannya sendiri di tests/Feature/Clinical. Memakai
     * alur penuh hanya akan menambah fixture asesmen yang tidak
     * memengaruhi angka laporan sama sekali.
     */
    private function diagnosa(string $kode, string $careType = 'ralan', string $penjamin = 'UMUM'): void
    {
        $nama = DB::table('clinical.diagnosis_codes')->where('code', $kode)->value('display') ?? $kode;

        $this->simpanDiagnosis($this->daftarkan($careType, $penjamin), $kode, $nama);
    }

    /** Diagnosis dengan kode yang sengaja tidak ada di kamus. */
    private function diagnosaMentah(string $kode, string $nama): void
    {
        $this->simpanDiagnosis($this->daftarkan('ralan', 'UMUM'), $kode, $nama);
    }

    private function simpanDiagnosis(Registration $registrasi, string $kode, string $nama): void
    {
        DB::table('clinical.diagnoses')->insert([
            'registration_id' => $registrasi->id,
            'patient_id' => $registrasi->patient_id,
            'registration_number' => $registrasi->registration_number,
            'code' => $kode,
            'display' => $nama,
            'rank' => 'utama',
            'certainty' => 'definitif',
            'diagnosed_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function daftarkan(string $careType, string $penjamin): Registration
    {
        static $urut = 0;
        $urut++;

        $pasien = app(PatientRegistry::class)->register([
            'name' => 'Pasien Morbid ' . $urut, 'sex' => 'L', 'birth_date' => '1990-01-01',
        ]);

        return $this->registrations->register(
            patientId: $pasien->id,
            unitId: Unit::query()->where('code', 'POL-UMUM')->firstOrFail()->id,
            payerId: Payer::query()->where('code', $penjamin)->value('id'),
            practitionerId: Practitioner::query()->where('is_active', true)->value('id'),
            extra: $careType === 'ranap' ? ['care_type' => 'ranap'] : [],
        );
    }
}
