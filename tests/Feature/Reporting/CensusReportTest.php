<?php

namespace Tests\Feature\Reporting;

use App\Modules\Catalog\Models\Payer;
use App\Modules\Encounter\Models\Registration;
use App\Modules\Encounter\Services\RegistrationService;
use App\Modules\Identity\Services\PatientRegistry;
use App\Modules\Inpatient\Models\Bed;
use App\Modules\Inpatient\Models\Room;
use App\Modules\Inpatient\Services\AdmissionService;
use App\Modules\Organization\Models\Practitioner;
use App\Modules\Organization\Models\Unit;
use App\Modules\Platform\Database\Seeders\PermissionCatalogSeeder;
use App\Modules\Platform\Database\Seeders\RoleSeeder;
use App\Modules\Platform\Models\Role;
use App\Modules\Platform\Models\User;
use App\Modules\Reporting\Services\CensusReportService;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Domain J item A: sensus & kunjungan.
 *
 * 21 kode laporan Khanza yang sesungguhnya potongan berbeda dari data
 * yang sama, dilayani satu layar berpenyaring — pola yang sama seperti
 * domain I item D.
 */
class CensusReportTest extends TestCase
{
    use RefreshDatabase;

    private CensusReportService $sensus;
    private RegistrationService $registrations;
    private User $manajemen;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([PermissionCatalogSeeder::class, RoleSeeder::class, ReferenceDataSeeder::class]);

        $this->sensus = app(CensusReportService::class);
        $this->registrations = app(RegistrationService::class);

        $this->manajemen = User::query()->create([
            'username' => 'uji-manajemen-sensus', 'name' => 'Manajemen Uji', 'password' => 'password', 'is_active' => true,
        ]);
        $this->manajemen->roles()->attach(Role::query()->where('code', 'manajemen')->firstOrFail());
    }

    #[Test]
    public function sensus_harian_memisahkan_rawat_jalan_dan_rawat_inap(): void
    {
        $this->daftarkan();
        $this->daftarkan();
        $this->daftarkan(careType: 'ranap');

        $hariIni = now()->toDateString();
        $sensus = $this->sensus->dailyCensus($hariIni, $hariIni)->keyBy('care_type');

        $this->assertSame(2, (int) $sensus['ralan']->jumlah);
        $this->assertSame(1, (int) $sensus['ranap']->jumlah);
    }

    /**
     * Kunjungan yang dibatalkan bukan kunjungan. Kalau ikut terhitung,
     * seluruh laporan sensus melaporkan angka yang lebih besar dari
     * kenyataan — dan itu jenis kesalahan yang tidak terlihat.
     */
    #[Test]
    public function kunjungan_yang_dibatalkan_tidak_ikut_dihitung(): void
    {
        $this->daftarkan();
        $batal = $this->daftarkan();
        $batal->update(['status' => 'batal']);

        $hariIni = now()->toDateString();

        $this->assertSame(1, (int) $this->sensus->dailyCensus($hariIni, $hariIni)->first()->jumlah);
        $this->assertSame(1, (int) $this->sensus->byUnit($hariIni, $hariIni)->first()->jumlah);

        // Tapi jumlahnya sendiri tetap dilaporkan.
        $this->assertSame(1, (int) $this->sensus->cancelled($hariIni, $hariIni)->first()->jumlah);
    }

    #[Test]
    public function penyaring_jenis_rawat_dan_unit_berlaku_pada_seluruh_potongan(): void
    {
        $poliUmum = Unit::query()->where('code', 'POL-UMUM')->firstOrFail();

        $this->daftarkan();
        $this->daftarkan(careType: 'ranap');

        $hariIni = now()->toDateString();

        foreach (['dailyCensus', 'byPractitioner', 'byPayer', 'arrivalByHour', 'byAgeGroup'] as $metode) {
            $semua = $this->sensus->{$metode}($hariIni, $hariIni);
            $ralan = $this->sensus->{$metode}($hariIni, $hariIni, 'ralan');

            $this->assertGreaterThanOrEqual(
                (int) $ralan->sum('jumlah'),
                (int) $semua->sum('jumlah'),
                "{$metode} harus menghormati penyaring jenis rawat"
            );
            $this->assertSame(1, (int) $ralan->sum('jumlah'), "{$metode} disaring ralan hanya menghitung satu");
        }

        // Penyaring unit juga berlaku.
        $this->assertSame(2, (int) $this->sensus->dailyCensus($hariIni, $hariIni, null, $poliUmum->id)->sum('jumlah'));
        $this->assertSame(0, (int) $this->sensus->dailyCensus($hariIni, $hariIni, null, 999_999)->sum('jumlah'));
    }

    #[Test]
    public function kelompok_umur_mengikuti_pembagian_laporan_kemenkes(): void
    {
        $this->daftarkanDenganUmur('Bayi Uji', now()->subMonths(6)->toDateString());
        $this->daftarkanDenganUmur('Anak Uji', now()->subYears(8)->toDateString());
        $this->daftarkanDenganUmur('Lansia Uji', now()->subYears(70)->toDateString());

        $hariIni = now()->toDateString();
        $kelompok = $this->sensus->byAgeGroup($hariIni, $hariIni)->pluck('jumlah', 'kelompok');

        $this->assertSame(1, (int) $kelompok['0-<1 th']);
        $this->assertSame(1, (int) $kelompok['5-14 th']);
        $this->assertSame(1, (int) $kelompok['65+ th']);
    }

    #[Test]
    public function pasien_tanpa_tanggal_lahir_tidak_merusak_kelompok_umur(): void
    {
        $pasien = app(PatientRegistry::class)->register(['name' => 'Tanpa Lahir', 'sex' => 'L']);
        $this->registrations->register(
            patientId: $pasien->id,
            unitId: Unit::query()->where('code', 'POL-UMUM')->value('id'),
            payerId: Payer::query()->where('code', 'UMUM')->value('id'),
        );
        $this->daftarkanDenganUmur('Dewasa Uji', now()->subYears(30)->toDateString());

        $hariIni = now()->toDateString();
        $kelompok = $this->sensus->byAgeGroup($hariIni, $hariIni);

        $this->assertSame(1, (int) $kelompok->sum('jumlah'), 'Yang tanpa tanggal lahir dilewati, bukan bikin galat');
        $this->assertSame('25-44 th', $kelompok->first()->kelompok);
    }

    #[Test]
    public function kedatangan_dikelompokkan_per_jam(): void
    {
        $this->travelTo(now()->setTime(9, 30));
        $this->daftarkan();
        $this->travelTo(now()->setTime(9, 45));
        $this->daftarkan();
        $this->travelTo(now()->setTime(14, 15));
        $this->daftarkan();

        $this->travelBack();
        $hariIni = now()->toDateString();
        $perJam = $this->sensus->arrivalByHour($hariIni, $hariIni)->pluck('jumlah', 'jam');

        $this->assertSame(2, (int) $perJam[9]);
        $this->assertSame(1, (int) $perJam[14]);
    }

    #[Test]
    public function admisi_terbaca_lewat_kontrak_terbitan_inpatient(): void
    {
        $registrasi = $this->daftarkan(careType: 'ranap');
        app(AdmissionService::class)->admit($registrasi->id, $this->bed());

        $hariIni = now()->toDateString();

        $this->assertCount(1, $this->sensus->admissions($hariIni, $hariIni));
        $this->assertSame(1, (int) $this->sensus->admissionsByRoom($hariIni, $hariIni)->first()->jumlah);
        $this->assertSame('K3-UJI', $this->sensus->admissionsByRoom($hariIni, $hariIni)->first()->room_number);
    }

    #[Test]
    public function asal_pasien_ranap_bisa_dilihat_per_poli_maupun_per_dokter(): void
    {
        $dokter = Practitioner::query()->where('is_active', true)->firstOrFail();
        $registrasi = $this->daftarkan(careType: 'ranap', practitionerId: $dokter->id);
        app(AdmissionService::class)->admit($registrasi->id, $this->bed());

        $hariIni = now()->toDateString();

        $perPoli = $this->sensus->admissionOrigin($hariIni, $hariIni, 'unit');
        $perDokter = $this->sensus->admissionOrigin($hariIni, $hariIni, 'dokter');

        $this->assertSame(1, (int) $perPoli->first()->jumlah);
        // Registrasi menyimpan nama berikut gelarnya, jadi yang dibandingkan
        // nama yang memang tercatat di kunjungan, bukan nama polos praktisi.
        $this->assertStringContainsString($dokter->name, $perDokter->first()->asal);
    }

    #[Test]
    public function layar_sensus_hanya_untuk_yang_berhak(): void
    {
        $this->actingAs($this->manajemen)->get(route('reporting.sensus'))->assertOk();

        $dokter = User::query()->create([
            'username' => 'uji-dokter-sensus', 'name' => 'Dokter Uji', 'password' => 'password', 'is_active' => true,
        ]);
        $dokter->roles()->attach(Role::query()->where('code', 'dokter')->firstOrFail());

        $this->actingAs($dokter)->get(route('reporting.sensus'))->assertForbidden();
    }

    #[Test]
    public function layar_sensus_menerima_penyaring_lewat_http(): void
    {
        $this->daftarkan();
        $this->daftarkan(careType: 'ranap');

        $this->actingAs($this->manajemen)
            ->get(route('reporting.sensus', ['jenis_rawat' => 'ranap', 'dari' => now()->toDateString(), 'sampai' => now()->toDateString()]))
            ->assertOk()
            ->assertSee('ranap');
    }

    // ------------------------------------------------------------------ bantu

    /** Kunjungan selalu punya dokter kecuali diminta lain — itu keadaan yang lazim,
     *  dan tanpa dokter rincian per dokter memang kosong secara sah. */
    private function daftarkan(string $careType = 'ralan', ?int $practitionerId = null): Registration
    {
        static $urut = 0;
        $urut++;

        $practitionerId ??= Practitioner::query()->where('is_active', true)->value('id');

        $pasien = app(PatientRegistry::class)->register([
            'name' => 'Pasien Sensus ' . $urut, 'sex' => 'L', 'birth_date' => '1990-01-01',
        ]);

        return $this->registrations->register(
            patientId: $pasien->id,
            unitId: Unit::query()->where('code', 'POL-UMUM')->firstOrFail()->id,
            payerId: Payer::query()->where('code', 'UMUM')->value('id'),
            practitionerId: $practitionerId,
            extra: $careType === 'ranap' ? ['care_type' => 'ranap'] : [],
        );
    }

    private function daftarkanDenganUmur(string $nama, string $tanggalLahir): Registration
    {
        $pasien = app(PatientRegistry::class)->register([
            'name' => $nama, 'sex' => 'L', 'birth_date' => $tanggalLahir,
        ]);

        return $this->registrations->register(
            patientId: $pasien->id,
            unitId: Unit::query()->where('code', 'POL-UMUM')->firstOrFail()->id,
            payerId: Payer::query()->where('code', 'UMUM')->value('id'),
        );
    }

    private function bed(): Bed
    {
        $kamar = Room::query()->create([
            'room_number' => 'K3-UJI', 'room_class' => 'kelas-3', 'daily_rate' => 250_000, 'is_active' => true,
        ]);

        return Bed::query()->create([
            'room_id' => $kamar->id, 'bed_number' => 'A', 'status' => Bed::STATUS_TERSEDIA,
        ]);
    }
}
