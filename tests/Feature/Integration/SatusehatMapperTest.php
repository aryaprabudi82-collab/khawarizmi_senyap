<?php

namespace Tests\Feature\Integration;

use App\Modules\Integration\Services\Satusehat\CodeMappingService;
use App\Modules\Integration\Services\Satusehat\Mappers\AllergyIntoleranceMapper;
use App\Modules\Integration\Services\Satusehat\Mappers\ObservationMapper;
use App\Modules\Integration\Services\Satusehat\Mappers\ProcedureMapper;
use App\Modules\Platform\Database\Seeders\PermissionCatalogSeeder;
use App\Modules\Platform\Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Mapper resource FHIR SATUSEHAT (domain L item D).
 *
 * Yang paling perlu dikunci: resource yang kodenya BELUM DIPETAKAN tidak
 * disusun sama sekali — bukan disusun dengan kode tebakan. Data klinis
 * bertanda kode salah yang sudah masuk platform nasional ikut terbaca
 * fasilitas lain yang merawat pasien yang sama, dan jauh lebih sulit
 * ditarik kembali daripada diperbaiki di laporan internal.
 */
class SatusehatMapperTest extends TestCase
{
    use RefreshDatabase;

    private CodeMappingService $pemetaan;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([PermissionCatalogSeeder::class, RoleSeeder::class]);

        $this->pemetaan = app(CodeMappingService::class);
    }

    // --------------------------------------------------------- Observation

    /** Inti: tanpa pemetaan, resource TIDAK disusun. */
    #[Test]
    public function observation_tidak_disusun_kalau_kodenya_belum_dipetakan(): void
    {
        $this->pemetaan->seedFrom('lab', [['code' => 'GLU', 'name' => 'Glukosa']]);

        $hasil = app(ObservationMapper::class)->build(
            $this->observasi('GLU', 120, 'mg/dL'), 'P-1', 'E-1', 'lab'
        );

        $this->assertNull($hasil, 'Belum dipetakan berarti jangan kirim, bukan kirim dengan kode tebakan');
    }

    #[Test]
    public function observation_disusun_dengan_kode_loinc_setelah_dipetakan(): void
    {
        $this->pemetaan->seedFrom('lab', [['code' => 'GLU', 'name' => 'Glukosa']]);
        $this->pemetaan->map('lab', 'GLU', 'loinc', '2345-7', 'Glucose [Mass/volume] in Serum');

        $hasil = app(ObservationMapper::class)->build(
            $this->observasi('GLU', 120, 'mg/dL'), 'P-1', 'E-1', 'lab'
        );

        $this->assertSame('Observation', $hasil['resourceType']);
        $this->assertSame('http://loinc.org', $hasil['code']['coding'][0]['system']);
        $this->assertSame('2345-7', $hasil['code']['coding'][0]['code']);
        $this->assertSame('Patient/P-1', $hasil['subject']['reference']);
        $this->assertSame('Encounter/E-1', $hasil['encounter']['reference']);
    }

    /**
     * Angka dikirim sebagai angka berikut satuannya. Mengirimnya sebagai
     * teks membuat nilainya tidak bisa dibandingkan antar waktu maupun
     * antar fasilitas — justru alasan utama data ini dikirim.
     */
    #[Test]
    public function nilai_angka_dikirim_sebagai_quantity_bukan_teks(): void
    {
        $this->pemetaan->seedFrom('lab', [['code' => 'GLU', 'name' => 'Glukosa']]);
        $this->pemetaan->map('lab', 'GLU', 'loinc', '2345-7');

        $hasil = app(ObservationMapper::class)->build(
            $this->observasi('GLU', 120, 'mg/dL'), 'P-1', 'E-1', 'lab'
        );

        $this->assertArrayHasKey('valueQuantity', $hasil);
        $this->assertArrayNotHasKey('valueString', $hasil);
        $this->assertSame(120.0, $hasil['valueQuantity']['value']);
        $this->assertSame('mg/dL', $hasil['valueQuantity']['unit']);
    }

    #[Test]
    public function nilai_teks_dikirim_sebagai_string(): void
    {
        $this->pemetaan->seedFrom('lab', [['code' => 'WRN', 'name' => 'Warna urin']]);
        $this->pemetaan->map('lab', 'WRN', 'loinc', '5778-6');

        $o = $this->observasi('WRN', null, null);
        $o->value_text = 'kuning jernih';

        $hasil = app(ObservationMapper::class)->build($o, 'P-1', 'E-1', 'lab');

        $this->assertSame('kuning jernih', $hasil['valueString']);
        $this->assertArrayNotHasKey('valueQuantity', $hasil);
    }

    /** Penanda abnormal adalah informasi klinis tersendiri, bukan turunan. */
    #[Test]
    public function nilai_abnormal_ditandai_pada_resource(): void
    {
        $this->pemetaan->seedFrom('lab', [['code' => 'GLU', 'name' => 'Glukosa']]);
        $this->pemetaan->map('lab', 'GLU', 'loinc', '2345-7');

        $o = $this->observasi('GLU', 400, 'mg/dL');
        $o->is_abnormal = true;

        $hasil = app(ObservationMapper::class)->build($o, 'P-1', 'E-1', 'lab');

        $this->assertSame('A', $hasil['interpretation'][0]['coding'][0]['code']);
    }

    #[Test]
    public function kategori_observation_mengikuti_jenis_pemetaannya(): void
    {
        foreach ([['lab', 'laboratory'], ['radiologi', 'vital-signs']] as [$jenis, $kategori]) {
            $this->pemetaan->seedFrom($jenis, [['code' => 'X-' . $jenis, 'name' => 'Uji']]);
            $this->pemetaan->map($jenis, 'X-' . $jenis, 'loinc', '1234-5');

            $hasil = app(ObservationMapper::class)->build(
                $this->observasi('X-' . $jenis, 1, 'x'), 'P-1', 'E-1', $jenis
            );

            $this->assertSame($kategori, $hasil['category'][0]['coding'][0]['code']);
        }
    }

    // ----------------------------------------------------------- Procedure

    #[Test]
    public function procedure_tidak_disusun_kalau_kodenya_belum_dipetakan(): void
    {
        $this->pemetaan->seedFrom('tindakan-ralan', [['code' => 'TL-001', 'name' => 'Jahit luka']]);

        $hasil = app(ProcedureMapper::class)->build($this->tindakan('TL-001'), 'P-1', 'E-1');

        $this->assertNull($hasil);
    }

    #[Test]
    public function procedure_disusun_dengan_kode_snomed_setelah_dipetakan(): void
    {
        $this->pemetaan->seedFrom('tindakan-ralan', [['code' => 'TL-001', 'name' => 'Jahit luka']]);
        $this->pemetaan->map('tindakan-ralan', 'TL-001', 'snomed', '288086009', 'Suture of wound');

        $hasil = app(ProcedureMapper::class)->build($this->tindakan('TL-001'), 'P-1', 'E-1');

        $this->assertSame('Procedure', $hasil['resourceType']);
        $this->assertSame('http://snomed.info/sct', $hasil['code']['coding'][0]['system']);
        $this->assertSame('288086009', $hasil['code']['coding'][0]['code']);
        $this->assertSame('completed', $hasil['status']);
    }

    // ------------------------------------------------------------- Alergi

    /**
     * Alergi SENGAJA tidak menuntut pemetaan kode: memaksa petugas memilih
     * dari daftar akan membuat alergi di luar daftar tidak tercatat sama
     * sekali, dan alergi yang hilang jauh lebih berbahaya daripada alergi
     * yang kodenya kurang presisi.
     */
    #[Test]
    public function alergi_tetap_dikirim_meski_tanpa_pemetaan_kode(): void
    {
        $hasil = app(AllergyIntoleranceMapper::class)->build($this->alergi('Amoksisilin'), 'P-1');

        $this->assertSame('AllergyIntolerance', $hasil['resourceType']);
        $this->assertSame('Amoksisilin', $hasil['code']['text'], 'Teks bebas, bukan kode');
        $this->assertSame('Patient/P-1', $hasil['patient']['reference']);
    }

    #[Test]
    public function kategori_dan_keparahan_alergi_dipetakan_ke_nilai_fhir(): void
    {
        $a = $this->alergi('Amoksisilin');
        $a->category = 'obat';
        $a->severity = 'berat';
        $a->reaction = 'Ruam menyeluruh';

        $hasil = app(AllergyIntoleranceMapper::class)->build($a, 'P-1');

        $this->assertSame(['medication'], $hasil['category']);
        $this->assertSame('severe', $hasil['reaction'][0]['severity']);
        $this->assertSame('Ruam menyeluruh', $hasil['reaction'][0]['manifestation'][0]['text']);
    }

    /** Kategori yang tidak dikenal tidak ditebak — cukup tidak dikirim. */
    #[Test]
    public function kategori_alergi_yang_tidak_dikenal_tidak_ditebak(): void
    {
        $a = $this->alergi('Debu');
        $a->category = 'lainnya';

        $hasil = app(AllergyIntoleranceMapper::class)->build($a, 'P-1');

        $this->assertArrayNotHasKey('category', $hasil);
    }

    #[Test]
    public function alergi_tidak_aktif_ditandai_inactive(): void
    {
        $a = $this->alergi('Amoksisilin');
        $a->status = 'tidak-aktif';

        $hasil = app(AllergyIntoleranceMapper::class)->build($a, 'P-1');

        $this->assertSame('inactive', $hasil['clinicalStatus']['coding'][0]['code']);
    }

    // ------------------------------------------------------------------ bantu

    private function observasi(string $kode, ?float $nilai, ?string $satuan): object
    {
        return (object) [
            'code' => $kode,
            'display' => 'Uji ' . $kode,
            'value_numeric' => $nilai,
            'value_text' => null,
            'unit' => $satuan,
            'is_abnormal' => false,
            'observed_at' => now(),
        ];
    }

    private function tindakan(string $kode): object
    {
        return (object) [
            'service_code' => $kode,
            'service_name' => 'Tindakan ' . $kode,
            'performed_at' => now(),
        ];
    }

    private function alergi(string $zat): object
    {
        return (object) [
            'substance' => $zat,
            'category' => null,
            'reaction' => null,
            'severity' => null,
            'status' => 'aktif',
            'recorded_at' => now(),
        ];
    }
}
