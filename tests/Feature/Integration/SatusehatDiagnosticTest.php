<?php

namespace Tests\Feature\Integration;

use App\Modules\Catalog\Models\Payer;
use App\Modules\Encounter\Models\Registration;
use App\Modules\Encounter\Services\RegistrationService;
use App\Modules\Identity\Services\PatientRegistry;
use App\Modules\Integration\Models\OutboundMessage;
use App\Modules\Integration\Services\IdentityMappingService;
use App\Modules\Integration\Services\OrderContext;
use App\Modules\Integration\Services\Satusehat\CodeMappingService;
use App\Modules\Integration\Services\Satusehat\DiagnosticSyncService;
use App\Modules\Integration\Services\Satusehat\FakeSatusehatClient;
use App\Modules\Integration\Services\Satusehat\SatusehatClient;
use App\Modules\Order\Database\Seeders\TestCatalogSeeder;
use App\Modules\Order\Models\LabRadiologyOrder;
use App\Modules\Order\Models\TestCatalog;
use App\Modules\Order\Services\OrderService;
use App\Modules\Organization\Models\Practitioner;
use App\Modules\Organization\Models\Unit;
use App\Modules\Platform\Database\Seeders\PermissionCatalogSeeder;
use App\Modules\Platform\Database\Seeders\RoleSeeder;
use App\Modules\Platform\Models\User;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

/**
 * Rantai penunjang SATUSEHAT (domain L item H).
 *
 * Yang paling perlu dikunci:
 *
 * 1. HASIL YANG BELUM DIVERIFIKASI TIDAK DIKIRIM. Hasil yang belum
 *    diverifikasi masih bisa berubah; menariknya kembali dari platform
 *    nasional jauh lebih sulit daripada mengirimnya terlambat.
 * 2. RANTAINYA BERURUTAN dan berhenti begitu ada yang gagal — melanjutkan
 *    setelah ServiceRequest ditolak berarti melaporkan pemeriksaan yang
 *    seolah dikerjakan tanpa ada yang meminta.
 * 3. RADIOLOGI TIDAK PUNYA SPESIMEN. Menyusun Specimen untuk foto toraks
 *    berarti melaporkan pengambilan bahan yang tidak pernah terjadi.
 */
class SatusehatDiagnosticTest extends TestCase
{
    use RefreshDatabase;

    private DiagnosticSyncService $sinkron;
    private OrderService $orders;
    private CodeMappingService $kode;
    private IdentityMappingService $pemetaan;
    private User $petugas;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([
            PermissionCatalogSeeder::class,
            RoleSeeder::class,
            ReferenceDataSeeder::class,
            TestCatalogSeeder::class,
        ]);

        $this->sinkron = app(DiagnosticSyncService::class);
        $this->orders = app(OrderService::class);
        $this->kode = app(CodeMappingService::class);
        $this->pemetaan = app(IdentityMappingService::class);

        $this->petugas = User::query()->create([
            'username' => 'uji-satusehat-penunjang', 'name' => 'Petugas Lab Uji',
            'password' => 'password', 'is_active' => true,
        ]);
    }

    // -------------------------------------------------------- rantai lengkap

    /**
     * INTI ITEM INI: keempat resource terkirim berurutan, dan yang belakangan
     * menunjuk ID yang baru diketahui dari yang lebih dulu.
     */
    #[Test]
    public function rantai_lab_terkirim_lengkap_dan_saling_menunjuk(): void
    {
        $order = $this->orderLabTerverifikasi();

        $hasil = $this->sinkron->syncOrder($order->id);

        $this->assertNull($hasil['gagal_pada']);
        $this->assertCount(2, $hasil['servicerequest']);
        $this->assertCount(2, $hasil['observation']);
        $this->assertNotNull($hasil['diagnosticreport']);

        // Satu bahan (darah) untuk dua pemeriksaan: satu tabung, bukan dua.
        $this->assertCount(1, $hasil['specimen']);

        $lembar = OutboundMessage::query()
            ->where('resource_type', 'diagnosticreport')
            ->where('source_id', $order->id)
            ->firstOrFail();

        $muatan = $lembar->request_payload;

        $this->assertSame('DiagnosticReport', $muatan['resourceType']);
        $this->assertCount(2, $muatan['result']);
        $this->assertSame(
            'Observation/' . $hasil['observation']['LAB-HB'],
            $muatan['result'][0]['reference']
        );
        $this->assertSame(
            'Specimen/' . reset($hasil['specimen']),
            $muatan['specimen'][0]['reference']
        );
    }

    /** Observation menunjuk permintaan dan bahannya, bukan berdiri sendiri. */
    #[Test]
    public function observation_menunjuk_servicerequest_dan_specimen(): void
    {
        $order = $this->orderLabTerverifikasi();
        $hasil = $this->sinkron->syncOrder($order->id);

        $muatan = $this->muatan('observation', $this->butirId($order, 'LAB-HB'));

        $this->assertSame(
            'ServiceRequest/' . $hasil['servicerequest']['LAB-HB'],
            $muatan['basedOn'][0]['reference']
        );
        $this->assertSame(
            'Specimen/' . reset($hasil['specimen']),
            $muatan['specimen']['reference']
        );
    }

    /**
     * "Hemoglobin 11,5 g/dL" tidak cukup untuk ditafsirkan fasilitas lain:
     * normal atau tidaknya bergantung rentang laboratorium yang memeriksanya.
     */
    #[Test]
    public function nilai_dikirim_sebagai_angka_berikut_rentang_rujukannya(): void
    {
        $order = $this->orderLabTerverifikasi(hb: 11.5);
        $this->sinkron->syncOrder($order->id);

        $muatan = $this->muatan('observation', $this->butirId($order, 'LAB-HB'));

        $this->assertSame(11.5, $muatan['valueQuantity']['value']);
        $this->assertSame('g/dL', $muatan['valueQuantity']['unit']);
        // Lewat JSON, 12.0 kembali sebagai 12 — yang penting nilainya, bukan tipenya.
        $this->assertEquals(12, $muatan['referenceRange'][0]['low']['value']);
        $this->assertEquals(16, $muatan['referenceRange'][0]['high']['value']);

        // Di bawah rentang: penandanya ikut dikirim.
        $this->assertSame('A', $muatan['interpretation'][0]['coding'][0]['code']);
    }

    // ------------------------------------------------------------ verifikasi

    /**
     * ATURAN PERTAMA: yang belum diverifikasi tidak dikirim sama sekali.
     */
    #[Test]
    public function hasil_yang_belum_diverifikasi_tidak_dikirim(): void
    {
        $order = $this->orderLabTerverifikasi(verifikasi: false);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('belum punya hasil terverifikasi');

        $this->sinkron->syncOrder($order->id);
    }

    #[Test]
    public function pembaca_konteks_menyaring_hasil_yang_belum_diverifikasi(): void
    {
        $order = $this->orderLabTerverifikasi(verifikasi: false);

        $this->assertCount(0, app(OrderContext::class)->verifiedResultsFor($order->id));

        $this->orders->verify($order->refresh(), $this->petugas);

        $this->assertCount(2, app(OrderContext::class)->verifiedResultsFor($order->id));
    }

    /** 'issued' adalah saat hasil dinyatakan sah, bukan saat angkanya diketik. */
    #[Test]
    public function lembar_hasil_memakai_waktu_verifikasi_sebagai_issued(): void
    {
        $order = $this->orderLabTerverifikasi();
        $this->sinkron->syncOrder($order->id);

        $muatan = $this->muatan('diagnosticreport', $order->id);

        $this->assertSame(
            $order->refresh()->verified_at->toIso8601String(),
            $muatan['issued']
        );
        $this->assertSame('Petugas Lab Uji', $muatan['resultsInterpreter'][0]['display']);
    }

    // -------------------------------------------------------------- radiologi

    /**
     * ATURAN KETIGA: foto rontgen tidak mengambil bahan dari pasien.
     */
    #[Test]
    public function radiologi_dikirim_tanpa_specimen(): void
    {
        $order = $this->orderRadiologiTerverifikasi();

        $hasil = $this->sinkron->syncOrder($order->id);

        $this->assertNull($hasil['gagal_pada']);
        $this->assertSame([], $hasil['specimen']);
        $this->assertSame(0, OutboundMessage::query()->where('resource_type', 'specimen')->count());

        $muatan = $this->muatan('observation', $this->butirId($order, 'RAD-THX'));

        $this->assertArrayNotHasKey('specimen', $muatan);
        $this->assertSame('imaging', $muatan['category'][0]['coding'][0]['code']);

        // Hasil radiologi naratif dikirim sebagai teks, bukan dipaksa jadi angka.
        $this->assertSame('Corakan bronkovaskular normal.', $muatan['valueString']);
    }

    // ---------------------------------------------------------- pemetaan kode

    /**
     * Menebak kode LOINC berarti mengirim hasil yang salah arti ke platform
     * nasional — yang belum dipetakan tidak dikirim, dan disebutkan.
     */
    #[Test]
    public function butir_yang_kodenya_belum_dipetakan_tidak_dikirim_dan_dilaporkan(): void
    {
        $order = $this->orderLabTerverifikasi(petakanLeukosit: false);

        $hasil = $this->sinkron->syncOrder($order->id);

        $this->assertNull($hasil['gagal_pada']);
        $this->assertSame(['LAB-LEU'], $hasil['belum_dipetakan']);
        $this->assertCount(1, $hasil['observation']);
        $this->assertArrayHasKey('LAB-HB', $hasil['observation']);
    }

    #[Test]
    public function tidak_ada_yang_dikirim_kalau_seluruh_kodenya_belum_dipetakan(): void
    {
        $order = $this->orderLabTerverifikasi(petakanHb: false, petakanLeukosit: false);

        $hasil = $this->sinkron->syncOrder($order->id);

        $this->assertSame('pemetaan kode', $hasil['gagal_pada']);
        $this->assertSame(0, OutboundMessage::query()->where('resource_type', 'diagnosticreport')->count());
    }

    // ------------------------------------------------------------- prasyarat

    #[Test]
    public function pasien_yang_belum_disinkronkan_menghentikan_pengiriman(): void
    {
        $order = $this->orderLabTerverifikasi(petakanPasien: false);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('harus sudah disinkronkan');

        $this->sinkron->syncOrder($order->id);
    }

    /**
     * Dokter perujuk bukan hiasan: ia yang bertanggung jawab atas
     * permintaannya, dan tanpa dia ServiceRequest tidak punya pemohon.
     */
    #[Test]
    public function dokter_perujuk_yang_belum_dipetakan_menghentikan_pengiriman(): void
    {
        $order = $this->orderLabTerverifikasi(petakanDokter: false);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Dokter yang meminta pemeriksaan belum dipetakan');

        $this->sinkron->syncOrder($order->id);
    }

    // ------------------------------------------------------------ pengulangan

    /**
     * Pengiriman ulang MEMPERBARUI resource yang sama, bukan membuat kembar.
     * Resource kembar di platform nasional membuat satu pemeriksaan terbaca
     * sebagai dua pemeriksaan berbeda.
     */
    #[Test]
    public function pengiriman_ulang_memakai_id_yang_sama(): void
    {
        $order = $this->orderLabTerverifikasi();

        $pertama = $this->sinkron->syncOrder($order->id);
        $kedua = $this->sinkron->syncOrder($order->id);

        $this->assertSame($pertama['diagnosticreport'], $kedua['diagnosticreport']);
        $this->assertSame($pertama['observation'], $kedua['observation']);
        $this->assertSame($pertama['specimen'], $kedua['specimen']);

        $this->assertSame(
            1,
            OutboundMessage::query()->where('resource_type', 'diagnosticreport')->count()
        );
    }

    /** Daftar antrean: yang sudah terkirim lembar hasilnya tidak muncul lagi. */
    #[Test]
    public function daftar_menunggu_kirim_menyusut_setelah_terkirim(): void
    {
        $order = $this->orderLabTerverifikasi();

        $this->assertCount(1, $this->sinkron->pending('lab'));

        $this->sinkron->syncOrder($order->id);

        $this->assertCount(0, $this->sinkron->pending('lab'));
    }

    // --------------------------------------------------------- rantai terputus

    /**
     * ATURAN KEDUA: melanjutkan setelah ServiceRequest ditolak berarti
     * melaporkan pemeriksaan yang seolah dikerjakan tanpa ada yang meminta.
     */
    #[Test]
    public function servicerequest_gagal_menghentikan_seluruh_sisa_rantai(): void
    {
        $order = $this->orderLabTerverifikasi();
        $this->clientYangGagalPada('ServiceRequest');

        $hasil = $this->sinkron->syncOrder($order->id);

        $this->assertSame('ServiceRequest', $hasil['gagal_pada']);
        $this->assertSame(0, OutboundMessage::query()->where('resource_type', 'specimen')->count());
        $this->assertSame(0, OutboundMessage::query()->where('resource_type', 'observation')->count());
        $this->assertSame(0, OutboundMessage::query()->where('resource_type', 'diagnosticreport')->count());

        // Kegagalannya tetap tercatat, bukan hilang tanpa jejak.
        $gagal = OutboundMessage::query()->where('resource_type', 'servicerequest')->firstOrFail();
        $this->assertSame(OutboundMessage::STATUS_FAILED, $gagal->status);
    }

    /**
     * Lembar hasil yang menunjuk pemeriksaan yang tidak ada di platform
     * nasional adalah lembar kosong yang tampak lengkap.
     */
    #[Test]
    public function observation_gagal_membatalkan_lembar_hasilnya(): void
    {
        $order = $this->orderLabTerverifikasi();
        $this->clientYangGagalPada('Observation');

        $hasil = $this->sinkron->syncOrder($order->id);

        $this->assertSame('Observation', $hasil['gagal_pada']);
        $this->assertSame(0, OutboundMessage::query()->where('resource_type', 'diagnosticreport')->count());

        // Yang sudah telanjur berhasil tetap diingat, supaya percobaan
        // berikutnya melanjutkan alih-alih menggandakan resource.
        $this->assertCount(2, $hasil['servicerequest']);
        $this->assertCount(1, $hasil['specimen']);
    }

    /** Setelah gangguannya lewat, pengiriman ulang menuntaskan sisanya. */
    #[Test]
    public function pengiriman_ulang_setelah_gagal_melanjutkan_bukan_mengulang(): void
    {
        $order = $this->orderLabTerverifikasi();
        $this->clientYangGagalPada('Observation');

        $gagal = $this->sinkron->syncOrder($order->id);

        // Gangguan berlalu: adapter kembali normal.
        $this->app->forgetInstance(SatusehatClient::class);
        $this->app->bind(SatusehatClient::class, FakeSatusehatClient::class);
        $this->sinkron = app(DiagnosticSyncService::class);

        $berhasil = $this->sinkron->syncOrder($order->id);

        $this->assertNull($berhasil['gagal_pada']);
        $this->assertSame($gagal['servicerequest'], $berhasil['servicerequest']);
        $this->assertSame($gagal['specimen'], $berhasil['specimen']);
        $this->assertNotNull($berhasil['diagnosticreport']);
    }

    // ------------------------------------------------------------------ bantu

    /** Adapter yang menolak satu jenis resource, untuk menguji rantai terputus. */
    private function clientYangGagalPada(string $fhirType): void
    {
        $this->app->bind(SatusehatClient::class, fn () => new class($fhirType) implements SatusehatClient
        {
            public function __construct(private readonly string $gagalPada) {}

            public function putResource(string $resourceType, ?string $existingId, array $resource): array
            {
                if ($resourceType === $this->gagalPada) {
                    return [
                        'success' => false,
                        'resource_id' => null,
                        'message' => "Ditolak SATUSEHAT ({$resourceType}).",
                        'response' => ['issue' => [['severity' => 'error']]],
                    ];
                }

                return (new FakeSatusehatClient)->putResource($resourceType, $existingId, $resource);
            }

            public function organizationId(): string
            {
                return 'fake-organization-simrs-mandiri';
            }
        });

        $this->sinkron = app(DiagnosticSyncService::class);
    }

    /** @return array<string, mixed> */
    private function muatan(string $resourceType, int $sourceId): array
    {
        return OutboundMessage::query()
            ->where('resource_type', $resourceType)
            ->where('source_id', $sourceId)
            ->firstOrFail()
            ->request_payload;
    }

    private function butirId(LabRadiologyOrder $order, string $kodeTes): int
    {
        return (int) $order->items()
            ->where('test_code', $kodeTes)
            ->value('id');
    }

    private function orderLabTerverifikasi(
        float $hb = 11.5,
        bool $verifikasi = true,
        bool $petakanHb = true,
        bool $petakanLeukosit = true,
        bool $petakanPasien = true,
        bool $petakanDokter = true,
    ): LabRadiologyOrder {
        $registrasi = $this->daftarkan();

        $order = $this->orders->create(
            $registrasi->id, 'lab',
            $this->idTes(['LAB-HB', 'LAB-LEU']),
            'Kontrol anemia.',
            $this->petugas
        );

        $this->orders->startProcessing($order);

        foreach ($order->refresh()->items as $butir) {
            $this->orders->enterResult(
                $butir,
                numeric: $butir->test_code === 'LAB-HB' ? $hb : 8.0,
                actor: $this->petugas
            );
        }

        $this->siapkanPemetaan($registrasi, $petakanPasien, $petakanDokter);

        $this->kode->seedFrom('lab', [
            ['code' => 'LAB-HB', 'name' => 'Hemoglobin'],
            ['code' => 'LAB-LEU', 'name' => 'Leukosit'],
        ]);

        if ($petakanHb) {
            $this->kode->map('lab', 'LAB-HB', 'loinc', '718-7', 'Hemoglobin [Mass/volume] in Blood');
        }

        if ($petakanLeukosit) {
            $this->kode->map('lab', 'LAB-LEU', 'loinc', '6690-2', 'Leukocytes [#/volume] in Blood');
        }

        if ($verifikasi) {
            $this->orders->verify($order->refresh(), $this->petugas);
        }

        return $order->refresh();
    }

    private function orderRadiologiTerverifikasi(): LabRadiologyOrder
    {
        $registrasi = $this->daftarkan();

        $order = $this->orders->create(
            $registrasi->id, 'radiologi', $this->idTes(['RAD-THX']), null, $this->petugas
        );

        $this->orders->startProcessing($order);

        $this->orders->enterResult(
            $order->refresh()->items->first(),
            text: 'Corakan bronkovaskular normal.',
            actor: $this->petugas
        );

        $this->siapkanPemetaan($registrasi);

        $this->kode->seedFrom('radiologi', [['code' => 'RAD-THX', 'name' => 'Rontgen Thorax PA']]);
        $this->kode->map('radiologi', 'RAD-THX', 'loinc', '42272-5', 'Chest X-ray');

        $this->orders->verify($order->refresh(), $this->petugas);

        return $order->refresh();
    }

    private function siapkanPemetaan(Registration $registrasi, bool $pasien = true, bool $dokter = true): void
    {
        if ($pasien) {
            $this->pemetaan->setManually(
                'satusehat', 'patient', 'identity', $registrasi->patient_id,
                'P-' . $registrasi->patient_id, $this->petugas->id
            );
        }

        $this->pemetaan->setManually(
            'satusehat', 'encounter', 'encounter', $registrasi->id,
            'E-' . $registrasi->id, $this->petugas->id
        );

        if ($dokter && $registrasi->practitioner_id !== null) {
            $this->pemetaan->setManually(
                'satusehat', 'practitioner', 'organization', $registrasi->practitioner_id,
                'PR-' . $registrasi->practitioner_id, $this->petugas->id
            );
        }
    }

    /** @return list<int> */
    private function idTes(array $kode): array
    {
        return TestCatalog::query()->whereIn('code', $kode)->orderBy('code')->pluck('id')->all();
    }

    private function daftarkan(): Registration
    {
        static $urut = 0;
        $urut++;

        $pasien = app(PatientRegistry::class)->register([
            'name' => 'Pasien Penunjang ' . $urut, 'sex' => 'P', 'birth_date' => '1985-03-03',
        ]);

        return app(RegistrationService::class)->register(
            patientId: $pasien->id,
            unitId: Unit::query()->where('code', 'POL-UMUM')->value('id'),
            payerId: Payer::query()->where('code', 'UMUM')->value('id'),
            practitionerId: Practitioner::query()->where('is_active', true)->value('id'),
        );
    }
}
