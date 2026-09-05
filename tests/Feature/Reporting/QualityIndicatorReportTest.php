<?php

namespace Tests\Feature\Reporting;

use App\Modules\Catalog\Models\Payer;
use App\Modules\Encounter\Models\Registration;
use App\Modules\Encounter\Services\RegistrationException;
use App\Modules\Encounter\Services\RegistrationService;
use App\Modules\Identity\Services\PatientRegistry;
use App\Modules\Inpatient\Database\Seeders\InpatientSeeder;
use App\Modules\Organization\Models\Practitioner;
use App\Modules\Organization\Models\Unit;
use App\Modules\Platform\Database\Seeders\PermissionCatalogSeeder;
use App\Modules\Platform\Database\Seeders\RoleSeeder;
use App\Modules\Platform\Models\Role;
use App\Modules\Platform\Models\User;
use App\Modules\Reporting\Services\QualityIndicatorReportService;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Domain J item D: indikator mutu & lama pelayanan.
 *
 * Yang paling penting diuji bukan rumusnya, melainkan dua aturan yang
 * menjaga angkanya tetap jujur: tahap yang belum tercatat DIKECUALIKAN
 * (bukan dihitung nol, yang akan membuat mutu terlihat lebih baik dari
 * kenyataan), dan urutan tahap tidak boleh dilompati (yang akan
 * menghasilkan waktu tunggu mustahil).
 */
class QualityIndicatorReportTest extends TestCase
{
    use RefreshDatabase;

    private QualityIndicatorReportService $mutu;
    private RegistrationService $registrations;
    private User $manajemen;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([
            PermissionCatalogSeeder::class, RoleSeeder::class,
            ReferenceDataSeeder::class, InpatientSeeder::class,
        ]);

        $this->mutu = app(QualityIndicatorReportService::class);
        $this->registrations = app(RegistrationService::class);

        $this->manajemen = User::query()->create([
            'username' => 'uji-manajemen-mutu', 'name' => 'Manajemen Uji', 'password' => 'password', 'is_active' => true,
        ]);
        $this->manajemen->roles()->attach(Role::query()->where('code', 'manajemen')->firstOrFail());
    }

    // ----------------------------------------------------- penegakan tahapan

    #[Test]
    public function tahap_pelayanan_naik_berurutan_dan_mencatat_waktunya(): void
    {
        $r = $this->daftarkan();
        $this->assertSame(Registration::STATUS_TERDAFTAR, $r->status);
        $this->assertNull($r->called_at);

        $r = $this->registrations->advance($r, Registration::STATUS_DIPANGGIL);
        $this->assertSame(Registration::STATUS_DIPANGGIL, $r->status);
        $this->assertNotNull($r->called_at);

        $r = $this->registrations->advance($r, Registration::STATUS_DILAYANI);
        $this->assertNotNull($r->served_at);

        $r = $this->registrations->advance($r, Registration::STATUS_SELESAI);
        $this->assertSame(Registration::STATUS_SELESAI, $r->status);
        $this->assertNotNull($r->finished_at);
    }

    /** Inti penegakan: tahap yang dilompati menghasilkan waktu tunggu mustahil. */
    #[Test]
    public function tahap_pelayanan_tidak_boleh_dilompati(): void
    {
        $r = $this->daftarkan();

        $this->expectException(RegistrationException::class);
        $this->expectExceptionMessage('tidak boleh dilompati');

        $this->registrations->advance($r, Registration::STATUS_SELESAI);
    }

    #[Test]
    public function tahap_yang_sudah_dilewati_tidak_bisa_diulang(): void
    {
        $r = $this->daftarkan();
        $r = $this->registrations->advance($r, Registration::STATUS_DIPANGGIL);

        $this->expectException(RegistrationException::class);
        $this->expectExceptionMessage('sudah melewati');

        $this->registrations->advance($r, Registration::STATUS_DIPANGGIL);
    }

    #[Test]
    public function kunjungan_batal_tidak_bisa_dilanjutkan(): void
    {
        $r = $this->daftarkan();
        $r = $this->registrations->cancel($r, 'salah input');

        $this->expectException(RegistrationException::class);

        $this->registrations->advance($r, Registration::STATUS_DIPANGGIL);
    }

    #[Test]
    public function pasien_yang_sudah_dilayani_tidak_bisa_ditandai_tidak_hadir(): void
    {
        $r = $this->daftarkan();
        $r = $this->registrations->advance($r, Registration::STATUS_DIPANGGIL);
        $r = $this->registrations->advance($r, Registration::STATUS_DILAYANI);

        $this->expectException(RegistrationException::class);

        $this->registrations->markNoShow($r);
    }

    // -------------------------------------------------- aturan kejujuran angka

    /**
     * Inti item ini: kunjungan yang belum dipanggil TIDAK boleh dihitung
     * sebagai waktu tunggu nol — itu akan menarik turun rata-rata dan
     * membuat mutu terlihat lebih baik daripada kenyataannya.
     */
    #[Test]
    public function tahap_yang_belum_tercatat_dikecualikan_bukan_dihitung_nol(): void
    {
        $selesai = $this->daftarkan();
        $this->majukanDenganJeda($selesai, 40);

        $this->daftarkan();   // sengaja dibiarkan berstatus 'terdaftar'

        $hariIni = now()->toDateString();
        $tunggu = $this->mutu->outpatientDuration($hariIni, $hariIni)->first();

        $this->assertSame(1, $tunggu->terhitung, 'Hanya yang lengkap yang dihitung');
        $this->assertSame(1, $tunggu->belum_lengkap, 'Yang belum lengkap dilaporkan, bukan disembunyikan');
        $this->assertEqualsWithDelta(40, (float) $tunggu->rata_menit, 1.0,
            'Rata-rata tetap 40 menit — kalau yang belum tercatat dihitung nol, jadi 20');
    }

    #[Test]
    public function kunjungan_tidak_hadir_tidak_ikut_menghitung_waktu_tunggu(): void
    {
        $hadir = $this->daftarkan();
        $this->majukanDenganJeda($hadir, 30);

        $this->registrations->markNoShow($this->daftarkan());

        $hariIni = now()->toDateString();
        $spm = $this->mutu->waitingTimeCompliance($hariIni, $hariIni);

        $this->assertSame(1, $spm->kunjungan, 'Yang tidak hadir tidak mengukur kelambatan rumah sakit');
        $this->assertSame(1, $spm->terhitung);
        $this->assertSame(0, $spm->belum_tercatat);
    }

    #[Test]
    public function kepatuhan_spm_menghitung_ambang_enam_puluh_menit(): void
    {
        $this->majukanDenganJeda($this->daftarkan(), 30);    // patuh
        $this->majukanDenganJeda($this->daftarkan(), 55);    // patuh
        $this->majukanDenganJeda($this->daftarkan(), 90);    // lewat

        $hariIni = now()->toDateString();
        $spm = $this->mutu->waitingTimeCompliance($hariIni, $hariIni);

        $this->assertSame(3, $spm->terhitung);
        $this->assertSame(2, $spm->patuh);
        $this->assertEqualsWithDelta(66.7, (float) $spm->persen, 0.1);
    }

    #[Test]
    public function rincian_per_unit_menandai_yang_lewat_spm(): void
    {
        $this->majukanDenganJeda($this->daftarkan(), 20);
        $this->majukanDenganJeda($this->daftarkan(), 75);

        $hariIni = now()->toDateString();
        $baris = $this->mutu->outpatientByUnit($hariIni, $hariIni)->firstWhere('unit_name', 'Poliklinik Umum');

        $this->assertSame(2, (int) $baris->kunjungan);
        $this->assertSame(1, (int) $baris->lewat_spm);
    }

    /** Penyaring unit harus berlaku pada seluruh potongan, bukan sebagian. */
    #[Test]
    public function penyaring_unit_berlaku_pada_seluruh_potongan(): void
    {
        $this->majukanDenganJeda($this->daftarkan('Poliklinik Umum'), 30);
        $this->majukanDenganJeda($this->daftarkan('Poliklinik Gigi dan Mulut'), 30);

        $hariIni = now()->toDateString();

        $this->assertSame(2, $this->mutu->outpatientDuration($hariIni, $hariIni)->first()->terhitung);
        $this->assertSame(1, $this->mutu->outpatientDuration($hariIni, $hariIni, 'Poliklinik Umum')->first()->terhitung);

        $this->assertSame(2, $this->mutu->waitingTimeCompliance($hariIni, $hariIni)->terhitung);
        $this->assertSame(1, $this->mutu->waitingTimeCompliance($hariIni, $hariIni, 'Poliklinik Umum')->terhitung);

        $this->assertCount(1, $this->mutu->outpatientByUnit($hariIni, $hariIni, 'Poliklinik Umum'));
    }

    // ------------------------------------------------------- efisiensi ranap

    #[Test]
    public function bor_dan_alos_kosong_saat_belum_ada_pasien_pulang(): void
    {
        $e = $this->mutu->bedEfficiency(now()->toDateString(), now()->toDateString());

        $this->assertSame(16, $e->tempat_tidur, 'Tempat tidur dari seeder ranap');
        $this->assertNull($e->alos, 'Kosong, bukan nol — nol berarti dirawat 0 hari');
        $this->assertNull($e->toi);
        $this->assertSame(0.0, (float) $e->bor, 'BOR memang 0% kalau tidak ada yang dirawat');
    }

    #[Test]
    public function alos_dihitung_dari_pasien_yang_sudah_pulang(): void
    {
        $admissions = app(\App\Modules\Inpatient\Services\AdmissionService::class);

        $admisi = $admissions->admit(
            $this->daftarkan(careType: 'ranap')->id,
            \App\Modules\Inpatient\Models\Bed::query()->firstOrFail(),
        );

        // Dimundurkan supaya lama rawatnya bisa ditentukan; admit() sendiri
        // selalu memakai now(), yang benar untuk pemakaian sungguhan.
        DB::table('inpatient.admissions')->where('id', $admisi->id)
            ->update(['admitted_at' => now()->subDays(4)]);
        DB::table('inpatient.bed_assignments')->where('admission_id', $admisi->id)
            ->update(['assigned_at' => now()->subDays(4)]);

        $admissions->discharge($admisi->refresh(), 'sembuh', null);

        $e = $this->mutu->bedEfficiency(now()->subDays(7)->toDateString(), now()->toDateString());

        $this->assertSame(1, $e->pasien_keluar);
        $this->assertEqualsWithDelta(4.0, (float) $e->alos, 0.1, 'Masuk 4 hari lalu, pulang hari ini');
        $this->assertGreaterThan(0, (float) $e->bor, 'Hari-rawat terpakai membuat BOR di atas nol');
    }

    // ------------------------------------------------------------------ layar

    #[Test]
    public function layar_mutu_hanya_untuk_yang_berhak(): void
    {
        $this->actingAs($this->manajemen)->get(route('reporting.mutu'))->assertOk();

        $kasir = User::query()->create([
            'username' => 'uji-kasir-mutu', 'name' => 'Kasir Uji', 'password' => 'password', 'is_active' => true,
        ]);
        $kasir->roles()->attach(Role::query()->where('code', 'kasir')->firstOrFail());

        $this->actingAs($kasir)->get(route('reporting.mutu'))->assertForbidden();
    }

    /** Layarnya wajib menyatakan apa yang belum bisa dilaporkan, bukan tampil lengkap. */
    #[Test]
    public function layar_mutu_menyatakan_yang_belum_bisa_dilaporkan(): void
    {
        $this->actingAs($this->manajemen)
            ->get(route('reporting.mutu'))
            ->assertOk()
            ->assertSee('tidak ikut dihitung rata-rata', false)
            ->assertSee('Lama Penyiapan RM', false)
            ->assertSee('Lama Pelayanan Lab MB', false);
    }

    // ------------------------------------------------------------------ bantu

    private function daftarkan(string $unitName = 'Poliklinik Umum', string $careType = 'ralan'): Registration
    {
        static $urut = 0;
        $urut++;

        $pasien = app(PatientRegistry::class)->register([
            'name' => 'Pasien Mutu ' . $urut, 'sex' => 'L', 'birth_date' => '1990-01-01',
        ]);

        return $this->registrations->register(
            patientId: $pasien->id,
            unitId: Unit::query()->where('name', $unitName)->firstOrFail()->id,
            payerId: Payer::query()->where('code', 'UMUM')->value('id'),
            practitionerId: Practitioner::query()->where('is_active', true)->value('id'),
            extra: $careType === 'ranap' ? ['care_type' => 'ranap'] : [],
        );
    }

    /**
     * Memajukan kunjungan sampai selesai dengan waktu tunggu yang dikarang.
     *
     * Stempelnya ditulis ulang lewat query supaya jedanya bisa ditentukan —
     * advance() sendiri selalu memakai now(), yang memang benar untuk
     * pemakaian sungguhan tapi tidak bisa dipakai menguji rentang.
     */
    private function majukanDenganJeda(Registration $r, int $menitTunggu): void
    {
        $r = $this->registrations->advance($r, Registration::STATUS_DIPANGGIL);
        $r = $this->registrations->advance($r, Registration::STATUS_DILAYANI);
        $r = $this->registrations->advance($r, Registration::STATUS_SELESAI);

        DB::table('encounter.registrations')->where('id', $r->id)->update([
            'registered_at' => now()->subMinutes($menitTunggu),
        ]);
    }
}
