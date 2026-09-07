<?php

namespace Tests\Feature\Clinical;

use App\Modules\Catalog\Models\FormTemplate;
use App\Modules\Catalog\Models\Payer;
use App\Modules\Catalog\Services\FormTemplateService;
use App\Modules\Clinical\Models\DiagnosticReport;
use App\Modules\Clinical\Services\ClinicalException;
use App\Modules\Clinical\Services\DiagnosticReportService;
use App\Modules\Encounter\Models\Registration;
use App\Modules\Encounter\Services\RegistrationService;
use App\Modules\Identity\Services\PatientRegistry;
use App\Modules\Organization\Models\Practitioner;
use App\Modules\Organization\Models\Unit;
use App\Modules\Platform\Database\Seeders\PermissionCatalogSeeder;
use App\Modules\Platform\Database\Seeders\RoleSeeder;
use App\Modules\Platform\Models\User;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Hasil pemeriksaan penunjang khusus (domain M item G).
 *
 * Yang paling perlu dikunci:
 *
 * 1. KESIMPULAN WAJIB SAAT DIFINALKAN — pengetatan terhadap Khanza yang
 *    membiarkannya kosong. Hasil tanpa kesimpulan adalah kumpulan angka
 *    yang tidak ditafsirkan siapa pun, dan pembacanya akan menganggap
 *    tidak ada temuan.
 * 2. PEMERIKSA WAJIB DISEBUT — tafsiran klinis pernyataan seseorang.
 * 3. TIDAK IDEMPOTEN: satu kunjungan boleh punya beberapa EKG, karena
 *    EKG diulang justru untuk melihat perubahannya.
 */
class DiagnosticReportTest extends TestCase
{
    use RefreshDatabase;

    private DiagnosticReportService $hasil;
    private User $dokter;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([PermissionCatalogSeeder::class, RoleSeeder::class, ReferenceDataSeeder::class]);

        $this->hasil = app(DiagnosticReportService::class);

        $this->dokter = User::query()->create([
            'username' => 'uji-penunjang', 'name' => 'dr. Pembaca EKG',
            'password' => 'password', 'is_active' => true,
        ]);
    }

    // ---------------------------------------------------------------- template

    #[Test]
    public function template_modalitas_tersedia_dan_belum_disahkan(): void
    {
        $tersedia = $this->hasil->availableTemplates()->pluck('code')->all();

        foreach (['hasil-ekg', 'hasil-usg-kandungan', 'hasil-echo', 'hasil-endoskopi-tht'] as $kode) {
            $this->assertContains($kode, $tersedia);
        }

        $this->assertSame(0, FormTemplate::query()
            ->where('category', FormTemplate::HASIL_PEMERIKSAAN)
            ->where('is_approved', true)
            ->count());
    }

    /** Butir EKG mengikuti kolom tabel Khanza, bukan dikarang. */
    #[Test]
    public function butir_ekg_mengikuti_kolom_khanza(): void
    {
        $ekg = app(FormTemplateService::class)->active('hasil-ekg');
        $kunci = collect($ekg->sections[0]['questions'])->pluck('key')->all();

        foreach (['irama', 'laju-jantung', 'gelombang-p', 'interval-pr', 'aksis', 'kompleks-qrs', 'segmen-st', 'gelombang-t'] as $k) {
            $this->assertContains($k, $kunci);
        }
    }

    /** Hasil penunjang tidak diskor — yang menyimpulkan pemeriksanya. */
    #[Test]
    public function template_hasil_pemeriksaan_tidak_punya_ambang_skor(): void
    {
        $ekg = app(FormTemplateService::class)->active('hasil-ekg');

        $this->assertNull($ekg->scoring);
    }

    #[Test]
    public function template_yang_bukan_hasil_pemeriksaan_ditolak(): void
    {
        $this->expectException(ClinicalException::class);
        $this->expectExceptionMessage('bukan template hasil pemeriksaan');

        $this->hasil->open($this->daftarkan()->id, 'risiko-jatuh-dewasa');
    }

    // -------------------------------------------------------------- pengisian

    #[Test]
    public function hasil_dibuka_membekukan_versi_templatenya(): void
    {
        $r = $this->hasil->open($this->daftarkan()->id, 'hasil-ekg', [], $this->dokter);

        $this->assertSame(1, $r->template_version);
        $this->assertSame('Hasil Pemeriksaan EKG', $r->template_name);
        $this->assertSame('ekg', $r->modality);
        $this->assertSame(DiagnosticReport::DRAF, $r->status);
        $this->assertFalse($r->template_approved);
    }

    /**
     * ATURAN KETIGA: EKG diulang justru untuk melihat perubahannya.
     */
    #[Test]
    public function satu_kunjungan_boleh_punya_beberapa_hasil_sejenis(): void
    {
        $registrasi = $this->daftarkan();

        $pagi = $this->hasil->open($registrasi->id, 'hasil-ekg');
        $siang = $this->hasil->open($registrasi->id, 'hasil-ekg');

        $this->assertNotSame($pagi->id, $siang->id);
        $this->assertSame(2, DiagnosticReport::query()->count());
    }

    #[Test]
    public function temuan_tersimpan_mengikuti_butir_templatenya(): void
    {
        $r = $this->hasil->open($this->daftarkan()->id, 'hasil-ekg', [], $this->dokter);

        $terisi = $this->hasil->save($r, [
            'irama' => 'Sinus',
            'laju-jantung' => '88',
            'segmen-st' => 'normal',
        ], ['conclusion' => 'EKG dalam batas normal.']);

        $this->assertSame('Sinus', $terisi->findings['irama']);
        $this->assertStringContainsString('batas normal', $terisi->conclusion);
    }

    #[Test]
    public function temuan_di_luar_butir_template_ditolak(): void
    {
        $r = $this->hasil->open($this->daftarkan()->id, 'hasil-ekg');

        $this->expectException(ClinicalException::class);
        $this->expectExceptionMessage('tidak dikenali template ini');

        $this->hasil->save($r, ['fraksi-ejeksi' => '55']);
    }

    // ------------------------------------------------------------ finalisasi

    /**
     * ATURAN PERTAMA, dan ini pengetatan terhadap Khanza.
     */
    #[Test]
    public function hasil_tanpa_kesimpulan_tidak_bisa_difinalkan(): void
    {
        $r = $this->hasil->open($this->daftarkan()->id, 'hasil-ekg', [], $this->dokter);
        $this->hasil->save($r, ['irama' => 'Sinus', 'laju-jantung' => '88']);

        $this->expectException(ClinicalException::class);
        $this->expectExceptionMessage('Kesimpulan wajib diisi');

        $this->hasil->finalize($r->refresh(), $this->dokter);
    }

    /** Dijaga basis data juga, bukan cuma service. */
    #[Test]
    public function basis_data_menolak_final_tanpa_kesimpulan(): void
    {
        $r = $this->hasil->open($this->daftarkan()->id, 'hasil-ekg');

        $this->expectException(QueryException::class);

        $r->update(['status' => DiagnosticReport::FINAL, 'finalized_at' => now()]);
    }

    /**
     * ATURAN KEDUA: tafsiran klinis adalah pernyataan seseorang.
     */
    #[Test]
    public function hasil_tanpa_pemeriksa_tidak_bisa_difinalkan(): void
    {
        $r = $this->hasil->open($this->daftarkan()->id, 'hasil-ekg', [], $this->dokter);
        $this->hasil->save($r, ['irama' => 'Sinus'], ['conclusion' => 'Normal.']);
        $r->update(['performed_by_name' => null]);

        $this->expectException(ClinicalException::class);
        $this->expectExceptionMessage('Nama pemeriksa wajib disebut');

        $this->hasil->finalize($r->refresh(), $this->dokter);
    }

    #[Test]
    public function hasil_lengkap_bisa_difinalkan(): void
    {
        $final = $this->hasilFinal();

        $this->assertTrue($final->isFinal());
        $this->assertNotNull($final->finalized_at);
        $this->assertNotNull($final->performed_by_name);
    }

    #[Test]
    public function hasil_final_tidak_bisa_diubah(): void
    {
        $final = $this->hasilFinal();

        $this->expectException(ClinicalException::class);
        $this->expectExceptionMessage('tidak boleh berubah tanpa jejak');

        $this->hasil->save($final, ['irama' => 'Atrial fibrilasi']);
    }

    #[Test]
    public function pembatalan_wajib_beralasan(): void
    {
        $r = $this->hasil->open($this->daftarkan()->id, 'hasil-ekg');

        $this->expectException(ClinicalException::class);
        $this->expectExceptionMessage('Alasan pembatalan wajib diisi');

        $this->hasil->cancel($r, '  ');
    }

    // ---------------------------------------------------------------- baca

    #[Test]
    public function hasil_final_terbaca_per_kunjungan(): void
    {
        $final = $this->hasilFinal();

        $daftar = $this->hasil->finalizedFor($final->registration_id);

        $this->assertCount(1, $daftar);
        $this->assertSame('hasil-ekg', $daftar->first()->template_code);
    }

    /** Draf tidak ikut daftar final — belum ditafsirkan siapa pun. */
    #[Test]
    public function draf_tidak_ikut_daftar_final(): void
    {
        $registrasi = $this->daftarkan();
        $this->hasil->open($registrasi->id, 'hasil-ekg');

        $this->assertCount(0, $this->hasil->finalizedFor($registrasi->id));
    }

    /** EKG hari ini hanya berarti bila dibandingkan dengan EKG sebelumnya. */
    #[Test]
    public function riwayat_pemeriksaan_terkumpul_lintas_kunjungan(): void
    {
        $pasien = app(PatientRegistry::class)->register([
            'name' => 'Pasien EKG Berulang', 'sex' => 'L', 'birth_date' => '1960-06-06',
        ]);

        foreach ([2, 1] as $mundur) {
            $registrasi = $this->daftarkan($pasien, $mundur);
            $r = $this->hasil->open($registrasi->id, 'hasil-ekg', [], $this->dokter);
            $this->hasil->save($r, ['irama' => 'Sinus'], ['conclusion' => 'Normal.']);
            $this->hasil->finalize($r->refresh(), $this->dokter);
        }

        $this->assertCount(2, $this->hasil->historyFor($pasien->id, 'hasil-ekg'));
    }

    /**
     * Pemeriksaan yang sudah dikerjakan tapi hasilnya tak pernah difinalkan
     * adalah pekerjaan yang hilang: pasien sudah menjalaninya, dan tidak
     * ada yang bisa membacanya.
     */
    #[Test]
    public function draf_yang_menggantung_lama_bisa_dicari(): void
    {
        $r = $this->hasil->open($this->daftarkan()->id, 'hasil-ekg');
        $r->update(['performed_at' => now()->subDays(3)]);

        $menggantung = $this->hasil->stalledDrafts();

        $this->assertCount(1, $menggantung);
        $this->assertSame($r->id, $menggantung->first()->id);
    }

    // ------------------------------------------------------------------ bantu

    private function hasilFinal(): DiagnosticReport
    {
        $r = $this->hasil->open($this->daftarkan()->id, 'hasil-ekg', [], $this->dokter);

        $this->hasil->save($r, [
            'irama' => 'Sinus', 'laju-jantung' => '88', 'segmen-st' => 'normal',
        ], ['conclusion' => 'EKG dalam batas normal.']);

        return $this->hasil->finalize($r->refresh(), $this->dokter);
    }

    private function daftarkan(?object $pasien = null, int $mundurHari = 0): Registration
    {
        static $urut = 0;
        $urut++;

        $pasien ??= app(PatientRegistry::class)->register([
            'name' => 'Pasien Penunjang ' . $urut, 'sex' => 'L', 'birth_date' => '1970-07-07',
        ]);

        return app(RegistrationService::class)->register(
            patientId: $pasien->id,
            unitId: Unit::query()->where('code', 'POL-UMUM')->value('id'),
            payerId: Payer::query()->where('code', 'UMUM')->value('id'),
            practitionerId: Practitioner::query()->where('is_active', true)->value('id'),
            serviceDate: now()->subDays($mundurHari),
        );
    }
}
