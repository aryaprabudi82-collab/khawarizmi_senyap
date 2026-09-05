<?php

namespace Tests\Feature\Reporting;

use App\Modules\Catalog\Models\Payer;
use App\Modules\Clinical\Database\Seeders\DiagnosisCodeSeeder;
use App\Modules\Encounter\Models\Registration;
use App\Modules\Encounter\Services\RegistrationService;
use App\Modules\Identity\Services\PatientRegistry;
use App\Modules\Inpatient\Database\Seeders\InpatientSeeder;
use App\Modules\Organization\Models\Practitioner;
use App\Modules\Organization\Models\Unit;
use App\Modules\Platform\Database\Seeders\PermissionCatalogSeeder;
use App\Modules\Platform\Database\Seeders\RoleSeeder;
use App\Modules\Platform\Models\Role;
use App\Modules\Platform\Models\User;
use App\Modules\Reporting\Services\StatutoryReportService;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Domain J item C: laporan RL Kemenkes.
 *
 * Yang paling penting diuji di sini bukan angkanya, melainkan
 * KEJUJURANNYA: sistem tidak boleh menyamarkan pengelompokan bab ICD-10
 * sebagai DTD, dan harus menyatakan sendiri kalau daftar resminya belum
 * diimpor. Laporan wajib yang tampak sesuai aturan padahal tidak jauh
 * lebih berbahaya daripada laporan yang mengakui kekurangannya.
 */
class StatutoryReportTest extends TestCase
{
    use RefreshDatabase;

    private StatutoryReportService $rl;
    private RegistrationService $registrations;
    private User $manajemen;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([
            PermissionCatalogSeeder::class, RoleSeeder::class, ReferenceDataSeeder::class,
            DiagnosisCodeSeeder::class, InpatientSeeder::class,
        ]);

        $this->rl = app(StatutoryReportService::class);
        $this->registrations = app(RegistrationService::class);

        $this->manajemen = User::query()->create([
            'username' => 'uji-manajemen-rl', 'name' => 'Manajemen Uji', 'password' => 'password', 'is_active' => true,
        ]);
        $this->manajemen->roles()->attach(Role::query()->where('code', 'manajemen')->firstOrFail());
    }

    #[Test]
    public function rl13_menghitung_tempat_tidur_per_kelas(): void
    {
        $tempatTidur = $this->rl->bedAvailability()->keyBy('room_class');

        $this->assertSame(6, $tempatTidur->count(), 'Enam kelas kamar dari seeder');
        $this->assertSame(1, (int) $tempatTidur['vip']->total);
        $this->assertSame(6, (int) $tempatTidur['kelas-3']->total);
        $this->assertSame(6, (int) $tempatTidur['kelas-3']->tersedia);
    }

    #[Test]
    public function rl13_tidak_menghitung_kamar_yang_dinonaktifkan(): void
    {
        $sebelum = (int) $this->rl->bedAvailability()->firstWhere('room_class', 'vip')->total;

        DB::table('inpatient.rooms')->where('room_class', 'vip')->update(['is_active' => false]);

        $this->assertNull(
            $this->rl->bedAvailability()->firstWhere('room_class', 'vip'),
            'Kamar yang ditutup bukan kapasitas yang tersedia'
        );
        $this->assertSame(1, $sebelum);
    }

    /**
     * Inti kejujuran item ini: selama DTD belum diimpor, sistem harus
     * mengatakannya, bukan menampilkan bab ICD-10 seolah itu DTD.
     */
    #[Test]
    public function rl4_menyatakan_kalau_dtd_belum_diimpor(): void
    {
        $this->assertFalse($this->rl->usingDtd(), 'Seeder sengaja tidak menebak DTD');
        $this->assertSame(16, $this->rl->unclassifiedDtdCount(), 'Seluruh kode contoh belum berkelompok DTD');
    }

    #[Test]
    public function rl4_beralih_ke_dtd_begitu_daftarnya_diimpor(): void
    {
        $this->diagnosa('J06.9', 'ranap');

        $hariIni = now()->toDateString();

        $sebelum = $this->rl->morbidityByCause('ranap', $hariIni, $hariIni);
        $this->assertSame('Pernapasan', $sebelum->first()->kelompok, 'Sebelum DTD: memakai bab ICD-10');

        DB::table('clinical.diagnosis_codes')->where('code', 'J06.9')->update(['dtd_group' => 'DTD-01 Infeksi saluran napas']);

        $this->assertTrue($this->rl->usingDtd());
        $this->assertSame(
            'DTD-01 Infeksi saluran napas',
            $this->rl->morbidityByCause('ranap', $hariIni, $hariIni)->first()->kelompok,
            'Setelah DTD diimpor: memakai DTD'
        );
    }

    #[Test]
    public function rl4_memisahkan_rawat_inap_dan_rawat_jalan(): void
    {
        $this->diagnosa('J06.9', 'ranap');
        $this->diagnosa('I10', 'ralan');

        $hariIni = now()->toDateString();

        $this->assertSame(1, (int) $this->rl->morbidityByCause('ranap', $hariIni, $hariIni)->sum('jumlah'));
        $this->assertSame(1, (int) $this->rl->morbidityByCause('ralan', $hariIni, $hariIni)->sum('jumlah'));
    }

    #[Test]
    public function rl4_merinci_menurut_golongan_umur_dan_jenis_kelamin(): void
    {
        $this->diagnosaDenganPasien('J06.9', 'ranap', 'Anak Uji', 'L', now()->subYears(8)->toDateString());
        $this->diagnosaDenganPasien('J06.9', 'ranap', 'Lansia Uji', 'P', now()->subYears(70)->toDateString());

        $hariIni = now()->toDateString();
        $rl4a = $this->rl->morbidity('ranap', $hariIni, $hariIni);

        $this->assertCount(2, $rl4a, 'Dua baris karena golongan umur dan jenis kelamin berbeda');
        $this->assertEqualsCanonicalizing(['5-14 th', '65+ th'], $rl4a->pluck('golongan_umur')->all());
        $this->assertEqualsCanonicalizing(['L', 'P'], $rl4a->pluck('sex')->all());
    }

    #[Test]
    public function rl32_menghitung_kunjungan_gawat_darurat_per_triase(): void
    {
        $registrasi = $this->daftarkan('ralan', 'Instalasi Gawat Darurat');

        DB::table('encounter.igd_triages')->insert([
            'registration_id' => $registrasi->id,
            'triage_level' => 'merah',
            'chief_complaint' => 'Sesak berat',
            'triaged_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $hariIni = now()->toDateString();
        $rl32 = $this->rl->emergencyActivity($hariIni, $hariIni);

        $this->assertCount(1, $rl32);
        $this->assertSame('merah', $rl32->first()->triage_level);
    }

    #[Test]
    public function rl33_dan_rl34_menghitung_kegiatan_unit_yang_dipilih(): void
    {
        $this->daftarkan('ralan', 'Poliklinik Gigi dan Mulut');
        $this->daftarkan('ralan', 'Poliklinik Gigi dan Mulut');
        $this->daftarkan('ralan', 'Poliklinik Kebidanan dan Kandungan');

        $hariIni = now()->toDateString();

        $this->assertSame(2, (int) $this->rl->unitActivity('Poliklinik Gigi dan Mulut', $hariIni, $hariIni)->sum('kunjungan'));
        $this->assertSame(1, (int) $this->rl->unitActivity('Poliklinik Kebidanan dan Kandungan', $hariIni, $hariIni)->sum('kunjungan'));
    }

    #[Test]
    public function rl37_dan_rl38_dipisahkan_menurut_kategori_penunjang(): void
    {
        $registrasi = $this->daftarkan();

        foreach ([['lab', 'LAB-001'], ['lab', 'LAB-002'], ['radiologi', 'RAD-001']] as [$kategori, $nomor]) {
            DB::table('orders.orders')->insert([
                'order_number' => $nomor,
                'registration_id' => $registrasi->id,
                'patient_id' => $registrasi->patient_id,
                'registration_number' => $registrasi->registration_number,
                'patient_mrn' => 'RM-UJI',
                'patient_name' => 'Pasien Uji',
                'category' => $kategori,
                'status' => 'diminta',
                'requested_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $hariIni = now()->toDateString();

        $this->assertSame(2, (int) $this->rl->supportActivity('lab', $hariIni, $hariIni)->sum('jumlah'));
        $this->assertSame(1, (int) $this->rl->supportActivity('radiologi', $hariIni, $hariIni)->sum('jumlah'));
    }

    #[Test]
    public function layar_rl_hanya_untuk_yang_berhak(): void
    {
        $this->actingAs($this->manajemen)->get(route('reporting.rl'))->assertOk();

        $kasir = User::query()->create([
            'username' => 'uji-kasir-rl', 'name' => 'Kasir Uji', 'password' => 'password', 'is_active' => true,
        ]);
        $kasir->roles()->attach(Role::query()->where('code', 'kasir')->firstOrFail());

        $this->actingAs($kasir)->get(route('reporting.rl'))->assertForbidden();
    }

    /** Layarnya wajib menyatakan batasnya, bukan tampil seolah siap kirim. */
    #[Test]
    public function layar_rl_menyatakan_batasnya_sendiri(): void
    {
        $this->actingAs($this->manajemen)
            ->get(route('reporting.rl'))
            ->assertOk()
            ->assertSee('bukan formulir RL siap kirim', false)
            ->assertSee('belum memakai Daftar Tabulasi Dasar', false);
    }

    // ------------------------------------------------------------------ bantu

    private function diagnosa(string $kode, string $careType): void
    {
        $this->diagnosaDenganPasien($kode, $careType, 'Pasien RL', 'L', '1990-01-01');
    }

    private function diagnosaDenganPasien(string $kode, string $careType, string $nama, string $sex, string $lahir): void
    {
        static $urut = 0;
        $urut++;

        $pasien = app(PatientRegistry::class)->register([
            'name' => $nama . ' ' . $urut, 'sex' => $sex, 'birth_date' => $lahir,
        ]);

        $registrasi = $this->registrations->register(
            patientId: $pasien->id,
            unitId: Unit::query()->where('code', 'POL-UMUM')->firstOrFail()->id,
            payerId: Payer::query()->where('code', 'UMUM')->value('id'),
            practitionerId: Practitioner::query()->where('is_active', true)->value('id'),
            extra: $careType === 'ranap' ? ['care_type' => 'ranap'] : [],
        );

        $nama = DB::table('clinical.diagnosis_codes')->where('code', $kode)->value('display') ?? $kode;

        DB::table('clinical.diagnoses')->insert([
            'registration_id' => $registrasi->id,
            'patient_id' => $pasien->id,
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

    private function daftarkan(string $careType = 'ralan', string $unitName = 'Poliklinik Umum'): Registration
    {
        static $urut = 0;
        $urut++;

        $pasien = app(PatientRegistry::class)->register([
            'name' => 'Pasien Unit ' . $urut, 'sex' => 'L', 'birth_date' => '1990-01-01',
        ]);

        return $this->registrations->register(
            patientId: $pasien->id,
            unitId: Unit::query()->where('name', $unitName)->firstOrFail()->id,
            payerId: Payer::query()->where('code', 'UMUM')->value('id'),
            practitionerId: Practitioner::query()->where('is_active', true)->value('id'),
            extra: $careType === 'ranap' ? ['care_type' => 'ranap'] : [],
        );
    }
}
