<?php

namespace Tests\Feature\Encounter;

use App\Modules\Catalog\Models\Payer;
use App\Modules\Encounter\Models\Registration;
use App\Modules\Encounter\Services\QueueNumberAllocator;
use App\Modules\Encounter\Services\RegistrationException;
use App\Modules\Encounter\Services\RegistrationService;
use App\Modules\Identity\Models\Patient;
use App\Modules\Identity\Services\PatientRegistry;
use App\Modules\Organization\Models\Practitioner;
use App\Modules\Organization\Models\Unit;
use App\Modules\Organization\Services\OrganizationAdminService;
use Carbon\CarbonImmutable;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class RegistrationTest extends TestCase
{
    use RefreshDatabase;

    private RegistrationService $service;
    private PatientRegistry $patients;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(ReferenceDataSeeder::class);

        $this->service = app(RegistrationService::class);
        $this->patients = app(PatientRegistry::class);
    }

    #[Test]
    public function nomor_rekam_medis_dialokasikan_berurutan_dan_unik(): void
    {
        $nomor = [];

        for ($i = 0; $i < 50; $i++) {
            $nomor[] = $this->patients->allocateMedicalRecordNumber();
        }

        $this->assertCount(50, array_unique($nomor), 'Ada nomor rekam medis yang kembar.');
        $this->assertSame('00000001', $nomor[0]);
        $this->assertSame('00000050', $nomor[49]);
    }

    #[Test]
    public function pasien_baru_terdaftar_dengan_nomor_rekam_medis_otomatis(): void
    {
        $pasien = $this->buatPasien(['name' => 'Budi Santoso', 'nik' => '3276010101900001']);

        $this->assertNotEmpty($pasien->medical_record_number);
        $this->assertSame('Budi Santoso', $pasien->name);
        $this->assertDatabaseHas('identity.patients', ['nik' => '3276010101900001']);
    }

    #[Test]
    public function nik_ganda_ditolak_dan_menunjuk_pasien_yang_sudah_ada(): void
    {
        $pertama = $this->buatPasien(['name' => 'Budi Santoso', 'nik' => '3276010101900001']);

        try {
            $this->buatPasien(['name' => 'Budi S', 'nik' => '3276010101900001']);
            $this->fail('Pendaftaran dengan NIK ganda seharusnya ditolak.');
        } catch (\App\Modules\Identity\Services\DuplicatePatientException $e) {
            $this->assertSame($pertama->id, $e->existing->id);
        }
    }

    #[Test]
    public function pasien_tanpa_nik_tetap_bisa_didaftarkan(): void
    {
        // Bayi baru lahir dan pasien gawat darurat tanpa identitas.
        $a = $this->buatPasien(['name' => 'Bayi Ny. Sari', 'nik' => null]);
        $b = $this->buatPasien(['name' => 'Tn. X', 'nik' => null]);

        $this->assertNotSame($a->medical_record_number, $b->medical_record_number);
    }

    #[Test]
    public function registrasi_pertama_ditandai_pasien_baru_dan_dapat_antrean_satu(): void
    {
        $registrasi = $this->daftarkan();

        $this->assertSame('baru', $registrasi->visit_type);
        $this->assertSame(1, $registrasi->queue_number);
        $this->assertSame(Registration::STATUS_TERDAFTAR, $registrasi->status);
        $this->assertStringStartsWith(CarbonImmutable::now()->format('Ymd') . '-', $registrasi->registration_number);
    }

    #[Test]
    public function kunjungan_berikutnya_ditandai_pasien_lama(): void
    {
        $pasien = $this->buatPasien();

        $this->daftarkan($pasien, tanggal: CarbonImmutable::now()->subDays(7));
        $kedua = $this->daftarkan($pasien);

        $this->assertSame('lama', $kedua->visit_type);
    }

    #[Test]
    public function nomor_antrean_berjalan_per_unit_per_hari(): void
    {
        $poliUmum = Unit::query()->where('code', 'POL-UMUM')->firstOrFail();
        $poliAnak = Unit::query()->where('code', 'POL-ANAK')->firstOrFail();

        $a = $this->daftarkan($this->buatPasien(), $poliUmum);
        $b = $this->daftarkan($this->buatPasien(), $poliUmum);
        $c = $this->daftarkan($this->buatPasien(), $poliAnak);

        $this->assertSame(1, $a->queue_number);
        $this->assertSame(2, $b->queue_number);
        $this->assertSame(1, $c->queue_number, 'Antrean tiap unit harus berdiri sendiri.');
    }

    #[Test]
    public function basis_data_menolak_nomor_antrean_kembar_walau_kode_lolos(): void
    {
        // Jaminan sesungguhnya ada di unique index, bukan di kesopanan kode.
        $pertama = $this->daftarkan();

        $this->expectException(QueryException::class);

        Registration::query()->create(array_merge(
            $pertama->only([
                'patient_id', 'unit_id', 'payer_id', 'patient_mrn', 'patient_name',
                'unit_name', 'payer_name', 'service_date', 'queue_number',
                'visit_type', 'care_type',
            ]),
            [
                'registration_number' => 'PAKSA-001',
                'registered_at' => now(),
                'status' => Registration::STATUS_TERDAFTAR,
            ]
        ));
    }

    #[Test]
    public function pendaftaran_ganda_di_unit_dan_hari_yang_sama_ditolak(): void
    {
        $pasien = $this->buatPasien();
        $this->daftarkan($pasien);

        $this->expectException(RegistrationException::class);
        $this->expectExceptionMessage('sudah terdaftar di unit ini');

        $this->daftarkan($pasien);
    }

    #[Test]
    public function tarif_registrasi_mengikuti_penjamin_dan_status_kunjungan(): void
    {
        $umum = Payer::query()->where('code', 'UMUM')->firstOrFail();
        $bpjs = Payer::query()->where('code', 'BPJS')->firstOrFail();

        $pasienUmum = $this->daftarkan($this->buatPasien(), payer: $umum);
        $this->assertSame('50000.00', $pasienUmum->registration_fee);
        $this->assertSame('belum-bayar', $pasienUmum->payment_status);

        $pasienBpjs = $this->daftarkan($this->buatPasien(), payer: $bpjs);
        $this->assertSame('0.00', $pasienBpjs->registration_fee);
        $this->assertSame('dijamin', $pasienBpjs->payment_status);
    }

    #[Test]
    public function pasien_lama_dikenai_tarif_kunjungan_ulang(): void
    {
        $pasien = $this->buatPasien();
        $poli = Unit::query()->where('code', 'POL-PD')->firstOrFail();

        $this->daftarkan($pasien, $poli, tanggal: CarbonImmutable::now()->subDays(30));
        $kedua = $this->daftarkan($pasien, $poli);

        $this->assertSame('lama', $kedua->visit_type);
        $this->assertSame('35000.00', $kedua->registration_fee);
    }

    #[Test]
    public function dokter_di_luar_masa_aktif_ditolak_sebagai_dpjp(): void
    {
        $dokter = Practitioner::query()->where('code', 'DR002')->firstOrFail();
        $dokter->update(['active_until' => CarbonImmutable::now()->subDay()->toDateString()]);

        $this->expectException(RegistrationException::class);
        $this->expectExceptionMessage('tidak aktif melayani');

        $this->service->register(
            patientId: $this->buatPasien()->id,
            unitId: Unit::query()->where('code', 'POL-PD')->value('id'),
            payerId: Payer::query()->where('code', 'UMUM')->value('id'),
            practitionerId: $dokter->id,
        );
    }

    #[Test]
    public function kuota_harian_unit_ditegakkan(): void
    {
        $poli = Unit::query()->where('code', 'POL-GIGI')->firstOrFail();
        $poli->update(['daily_quota' => 2]);

        $this->daftarkan($this->buatPasien(), $poli);
        $this->daftarkan($this->buatPasien(), $poli);

        $this->expectException(RegistrationException::class);
        $this->expectExceptionMessage('Kuota harian');

        $this->daftarkan($this->buatPasien(), $poli);
    }

    #[Test]
    public function umur_direkam_pada_saat_pendaftaran(): void
    {
        $pasien = $this->buatPasien([
            'birth_date' => CarbonImmutable::now()->subYears(34)->subMonths(5)->toDateString(),
        ]);

        $registrasi = $this->daftarkan($pasien);

        $this->assertSame(34, $registrasi->age_years);
        $this->assertSame(5, $registrasi->age_months);
    }

    #[Test]
    public function pembatalan_membebaskan_nomor_antrean_dari_unique_index(): void
    {
        $registrasi = $this->daftarkan();

        $this->service->cancel($registrasi, 'Pasien pulang sebelum dilayani.');

        $this->assertSame(Registration::STATUS_BATAL, $registrasi->fresh()->status);

        // Pasien lain masih bisa mendaftar; nomor berikutnya tetap berjalan maju.
        $berikutnya = $this->daftarkan();
        $this->assertSame(2, $berikutnya->queue_number);
    }

    #[Test]
    public function registrasi_yang_sudah_selesai_tidak_bisa_dibatalkan(): void
    {
        $registrasi = $this->daftarkan();
        $registrasi->update(['status' => Registration::STATUS_SELESAI]);

        $this->expectException(RegistrationException::class);

        $this->service->cancel($registrasi->fresh(), 'terlambat');
    }

    #[Test]
    public function papan_antrean_dirender_tanpa_menyeberang_konteks(): void
    {
        $poli = Unit::query()->where('code', 'POL-UMUM')->firstOrFail();

        $this->daftarkan($this->buatPasien(['name' => 'Ani Lestari']), $poli);
        $this->daftarkan($this->buatPasien(['name' => 'Joko Prasetyo']), $poli);

        // Satu kueri ke satu tabel: nama pasien, poli, dan dokter sudah tersalin.
        $antrean = Registration::query()
            ->queueFor(CarbonImmutable::now(), $poli->id)
            ->get(['queue_number', 'patient_mrn', 'patient_name', 'unit_name', 'status']);

        $this->assertCount(2, $antrean);
        $this->assertSame('Ani Lestari', $antrean[0]->patient_name);
        $this->assertSame('Poliklinik Umum', $antrean[0]->unit_name);
        $this->assertSame(1, $antrean[0]->queue_number);
    }

    #[Test]
    public function pengalokasi_antrean_tidak_pernah_mengeluarkan_nomor_kembar(): void
    {
        $poli = Unit::query()->where('code', 'POL-MATA')->firstOrFail();
        $allocator = app(QueueNumberAllocator::class);

        $nomor = [];

        for ($i = 0; $i < 200; $i++) {
            $nomor[] = $allocator->allocate(CarbonImmutable::now(), $poli->id);
        }

        $this->assertCount(200, array_unique($nomor));
        $this->assertSame(range(1, 200), $nomor, 'Nomor antrean harus berurutan tanpa lompatan.');
        $this->assertSame(200, $allocator->current(CarbonImmutable::now(), $poli->id));
    }

    #[Test]
    public function booking_ke_tanggal_mendatang_dengan_jadwal_cocok_berhasil(): void
    {
        $dokter = Practitioner::query()->where('code', 'DR001')->firstOrFail();
        $unit = Unit::query()->where('code', 'POL-UMUM')->firstOrFail();
        $tanggal = $this->tanggalHariKerja($dokter, $unit);

        $registrasi = $this->service->register(
            patientId: $this->buatPasien()->id,
            unitId: $unit->id,
            payerId: Payer::query()->where('code', 'UMUM')->value('id'),
            practitionerId: $dokter->id,
            serviceDate: $tanggal,
        );

        $this->assertSame($tanggal->toDateString(), $registrasi->service_date->toDateString());
    }

    #[Test]
    public function booking_ke_tanggal_mendatang_tanpa_jadwal_cocok_ditolak(): void
    {
        $dokter = Practitioner::query()->where('code', 'DR001')->firstOrFail();
        $unit = Unit::query()->where('code', 'POL-UMUM')->firstOrFail();

        // Dokter ini sengaja tidak diberi practice_schedules sama sekali,
        // jadi tanggal mendatang apa pun harus ditolak.
        $tanggal = CarbonImmutable::now()->addWeek()->startOfDay();

        $this->expectException(RegistrationException::class);
        $this->service->register(
            patientId: $this->buatPasien()->id,
            unitId: $unit->id,
            payerId: Payer::query()->where('code', 'UMUM')->value('id'),
            practitionerId: $dokter->id,
            serviceDate: $tanggal,
        );
    }

    #[Test]
    public function booking_tanpa_memilih_dokter_tidak_diperiksa_terhadap_jadwal(): void
    {
        $unit = Unit::query()->where('code', 'POL-UMUM')->firstOrFail();
        $tanggal = CarbonImmutable::now()->addWeek()->startOfDay();

        $registrasi = $this->service->register(
            patientId: $this->buatPasien()->id,
            unitId: $unit->id,
            payerId: Payer::query()->where('code', 'UMUM')->value('id'),
            serviceDate: $tanggal,
        );

        $this->assertNull($registrasi->practitioner_id);
    }

    #[Test]
    public function registrasi_hari_ini_tidak_diperiksa_terhadap_jadwal_praktik(): void
    {
        // Walk-in hari ini tetap harus berhasil meski dokternya tidak punya
        // practice_schedules sama sekali — jadwal cuma menahan booking ke
        // depan, bukan mengganti kebijaksanaan loket untuk kunjungan hari ini.
        $dokter = Practitioner::query()->where('code', 'DR001')->firstOrFail();
        $unit = Unit::query()->where('code', 'POL-UMUM')->firstOrFail();

        $registrasi = $this->service->register(
            patientId: $this->buatPasien()->id,
            unitId: $unit->id,
            payerId: Payer::query()->where('code', 'UMUM')->value('id'),
            practitionerId: $dokter->id,
        );

        $this->assertSame($dokter->id, $registrasi->practitioner_id);
    }

    // ------------------------------------------------------------------ bantu

    /** Tanggal terdekat di masa depan yang cocok dengan jadwal praktik yang baru dibuat. */
    private function tanggalHariKerja(Practitioner $dokter, Unit $unit): CarbonImmutable
    {
        $tanggal = CarbonImmutable::now()->addWeek()->startOfDay();

        app(OrganizationAdminService::class)->addSchedule($dokter, [
            'unit_id' => $unit->id,
            'day_of_week' => $tanggal->dayOfWeekIso,
            'start_time' => '08:00',
            'end_time' => '12:00',
        ]);

        return $tanggal;
    }

    private function buatPasien(array $override = []): Patient
    {
        static $urutan = 0;
        $urutan++;

        return $this->patients->register(array_merge([
            'name' => 'Pasien Uji ' . $urutan,
            'sex' => 'L',
            'birth_date' => '1990-01-01',
            'birth_place' => 'Depok',
            'nik' => null,
        ], $override));
    }

    private function daftarkan(
        ?Patient $pasien = null,
        ?Unit $unit = null,
        ?Payer $payer = null,
        ?CarbonImmutable $tanggal = null,
    ): Registration {
        return $this->service->register(
            patientId: ($pasien ?? $this->buatPasien())->id,
            unitId: ($unit ?? Unit::query()->where('code', 'POL-UMUM')->firstOrFail())->id,
            payerId: ($payer ?? Payer::query()->where('code', 'UMUM')->firstOrFail())->id,
            serviceDate: $tanggal,
        );
    }
}
