<?php

namespace Tests\Feature\Clinical;

use App\Modules\Catalog\Models\FluidItem;
use App\Modules\Catalog\Models\Payer;
use App\Modules\Clinical\Models\DialysisSerology;
use App\Modules\Clinical\Models\DialysisSession;
use App\Modules\Clinical\Services\ClinicalException;
use App\Modules\Clinical\Services\DialysisService;
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
 * Hemodialisa (domain M item P).
 *
 * Yang paling perlu dikunci:
 *
 * 1. LAMA DIALISIS DIHITUNG, bukan diketik — Khanza punya kolom `lama`
 *    tanpa jam mulai maupun selesai.
 * 2. SEROLOGI MELEKAT PADA PASIEN, bukan disalin ke ratusan sesi.
 * 3. "BELUM DIPERIKSA" BUKAN "TIDAK PERLU MESIN TERPISAH".
 * 4. PARAMETER MESIN TERPISAH DARI TANDA VITAL PASIEN.
 * 5. BALANS CAIRAN MEMAKAI MASTER YANG SAMA DENGAN BANGSAL.
 */
class DialysisTest extends TestCase
{
    use RefreshDatabase;

    private DialysisService $dialisis;

    private User $perawatHd;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([PermissionCatalogSeeder::class, RoleSeeder::class, ReferenceDataSeeder::class]);

        $this->dialisis = app(DialysisService::class);

        $this->perawatHd = User::query()->create([
            'username' => 'uji-hd', 'name' => 'Ns. Perawat HD',
            'password' => 'password', 'is_active' => true,
        ]);
    }

    // ============================================== serologi

    #[Test]
    public function serologi_melekat_pada_pasien_bukan_pada_sesi(): void
    {
        $kunjungan = $this->daftarkan();

        $this->dialisis->recordSerology($kunjungan->patient_id, [
            'hbsag' => DialysisSerology::NON_REAKTIF,
            'anti_hcv' => DialysisSerology::NON_REAKTIF,
            'anti_hiv' => DialysisSerology::NON_REAKTIF,
        ], $this->perawatHd);

        $pertama = $this->dialisis->start($kunjungan->id, [], $this->perawatHd);
        $kedua = $this->dialisis->start($this->daftarkan($kunjungan->patient)->id, [], $this->perawatHd);

        // Khanza menyimpan hbsag/hiv/hcv pada SETIAP baris hemodialisa.
        foreach (['hbsag', 'anti_hcv', 'anti_hiv'] as $kolom) {
            $this->assertArrayNotHasKey($kolom, $pertama->getAttributes());
            $this->assertArrayNotHasKey($kolom, $kedua->getAttributes());
        }

        $this->assertSame(1, DialysisSerology::query()->where('patient_id', $kunjungan->patient_id)->count());
    }

    #[Test]
    public function pasien_hbsag_reaktif_menuntut_mesin_terpisah(): void
    {
        $kunjungan = $this->daftarkan();

        $this->dialisis->recordSerology($kunjungan->patient_id, [
            'hbsag' => DialysisSerology::REAKTIF,
            'anti_hcv' => DialysisSerology::NON_REAKTIF,
            'anti_hiv' => DialysisSerology::NON_REAKTIF,
        ], $this->perawatHd);

        $this->assertTrue($this->dialisis->requiresDedicatedMachine($kunjungan->patient_id));
    }

    #[Test]
    public function belum_diperiksa_bukan_berarti_tidak_perlu_mesin_terpisah(): void
    {
        $kunjungan = $this->daftarkan();

        // null, bukan false: menyamakan "belum diketahui" dengan "tidak
        // perlu" akan menempatkan pasien yang belum diperiksa pada mesin
        // bersama.
        $this->assertNull($this->dialisis->requiresDedicatedMachine($kunjungan->patient_id));
    }

    #[Test]
    public function serologi_yang_kedaluwarsa_tidak_lagi_menjawab_pertanyaan_mesin(): void
    {
        $kunjungan = $this->daftarkan();

        $this->dialisis->recordSerology($kunjungan->patient_id, [
            'hbsag' => DialysisSerology::NON_REAKTIF,
            'anti_hcv' => DialysisSerology::NON_REAKTIF,
            'anti_hiv' => DialysisSerology::NON_REAKTIF,
            'tested_on' => now()->subMonths(8)->toDateString(),
        ], $this->perawatHd);

        $this->assertNull($this->dialisis->requiresDedicatedMachine($kunjungan->patient_id));
    }

    #[Test]
    public function masa_berlaku_serologi_dihitung_bukan_disimpan(): void
    {
        $kunjungan = $this->daftarkan();

        $serologi = $this->dialisis->recordSerology($kunjungan->patient_id, [
            'hbsag' => DialysisSerology::NON_REAKTIF,
            'anti_hcv' => DialysisSerology::NON_REAKTIF,
            'anti_hiv' => DialysisSerology::NON_REAKTIF,
            'tested_on' => now()->subMonths(2)->toDateString(),
        ], $this->perawatHd);

        $this->assertSame(
            $serologi->tested_on->copy()->addMonths(6)->toDateString(),
            $serologi->expiresOn()->toDateString()
        );
        $this->assertArrayNotHasKey('expires_on', $serologi->getAttributes());
        $this->assertTrue($serologi->isValid());
    }

    #[Test]
    public function hasil_serologi_di_luar_kosakata_ditolak(): void
    {
        $kunjungan = $this->daftarkan();

        $this->expectException(ClinicalException::class);
        $this->expectExceptionMessageMatches("/'mungkin' untuk hbsag tidak dikenali/");

        $this->dialisis->recordSerology($kunjungan->patient_id, ['hbsag' => 'mungkin'], $this->perawatHd);
    }

    #[Test]
    public function pasien_yang_serologinya_kedaluwarsa_bisa_ditagih(): void
    {
        $kunjungan = $this->daftarkan();
        $this->dialisis->start($kunjungan->id, [
            'serology_override_reason' => 'Dialisis cito, laboratorium menyusul',
        ], $this->perawatHd);

        $this->assertContains(
            $kunjungan->patient_id,
            $this->dialisis->serologyDue()->pluck('patient_id')->all()
        );
    }

    // ============================================== memulai sesi

    #[Test]
    public function sesi_tidak_dimulai_tanpa_serologi_yang_berlaku(): void
    {
        $kunjungan = $this->daftarkan();

        $this->expectException(ClinicalException::class);
        $this->expectExceptionMessageMatches('/harus memakai mesin terpisah/');

        $this->dialisis->start($kunjungan->id, [], $this->perawatHd);
    }

    #[Test]
    public function dialisis_cito_tetap_bisa_dimulai_dengan_alasan_tertulis(): void
    {
        $kunjungan = $this->daftarkan();

        // Bukan larangan mutlak: sistem yang melarangnya akan dilewati
        // dengan mencatat serologi karangan, yang jauh lebih berbahaya.
        $sesi = $this->dialisis->start($kunjungan->id, [
            'serology_override_reason' => 'Hiperkalemia berat, dialisis tidak bisa ditunda',
        ], $this->perawatHd);

        $this->assertSame(DialysisSession::BERJALAN, $sesi->status);
    }

    #[Test]
    public function serologi_yang_kedaluwarsa_menyebutkan_tanggalnya(): void
    {
        $kunjungan = $this->daftarkan();

        $this->dialisis->recordSerology($kunjungan->patient_id, [
            'hbsag' => DialysisSerology::NON_REAKTIF,
            'anti_hcv' => DialysisSerology::NON_REAKTIF,
            'anti_hiv' => DialysisSerology::NON_REAKTIF,
            'tested_on' => now()->subMonths(9)->toDateString(),
        ], $this->perawatHd);

        $this->expectException(ClinicalException::class);
        $this->expectExceptionMessageMatches('/sudah lewat masa berlaku 6 bulan/');

        $this->dialisis->start($kunjungan->id, [], $this->perawatHd);
    }

    #[Test]
    public function jenis_akses_di_luar_kosakata_ditolak(): void
    {
        $kunjungan = $this->siapPeriksa();

        $this->expectException(ClinicalException::class);
        $this->expectExceptionMessageMatches("/'selang biasa' tidak dikenali/");

        $this->dialisis->start($kunjungan->id, ['access_type' => 'selang biasa'], $this->perawatHd);
    }

    #[Test]
    public function satu_kunjungan_satu_sesi(): void
    {
        $kunjungan = $this->siapPeriksa();

        $pertama = $this->dialisis->start($kunjungan->id, [], $this->perawatHd);
        $kedua = $this->dialisis->start($kunjungan->id, [], $this->perawatHd);

        $this->assertSame($pertama->id, $kedua->id);
    }

    // ============================================== durasi dihitung

    #[Test]
    public function lama_dialisis_dihitung_dari_jam_mulai_dan_selesai(): void
    {
        $sesi = $this->sesi(['started_at' => now()->subMinutes(245), 'prescribed_minutes' => 240]);
        $selesai = $this->dialisis->finish($sesi, ['ended_at' => now()]);

        // Khanza menyimpan kolom `lama` yang diketik tanpa punya kedua jam
        // ini — jadi tidak ada yang bisa memeriksa apakah angkanya benar.
        $this->assertSame(245, $selesai->achievedMinutes());
        $this->assertArrayNotHasKey('lama', $selesai->getAttributes());
        $this->assertArrayNotHasKey('duration_minutes', $selesai->getAttributes());
    }

    #[Test]
    public function sesi_yang_durasinya_tidak_tercapai_bisa_disebutkan(): void
    {
        $kurang = $this->sesi(['started_at' => now()->subMinutes(150), 'prescribed_minutes' => 240]);
        $this->dialisis->terminate($kurang, 'Hipotensi berulang', ['ended_at' => now()]);

        $cukup = $this->sesi(['started_at' => now()->subMinutes(240), 'prescribed_minutes' => 240]);
        $this->dialisis->finish($cukup, ['ended_at' => now()]);

        $this->assertTrue($kurang->refresh()->isTimeShortfall());
        $this->assertFalse($cukup->refresh()->isTimeShortfall());

        $pendek = $this->dialisis
            ->shortSessions(now()->subDay()->toDateTimeString(), now()->addDay()->toDateTimeString())
            ->pluck('id')->all();

        $this->assertContains($kurang->id, $pendek);
        $this->assertNotContains($cukup->id, $pendek);
    }

    #[Test]
    public function penghentian_tanpa_alasan_ditolak(): void
    {
        $sesi = $this->sesi();

        $this->expectException(ClinicalException::class);
        $this->expectExceptionMessageMatches('/kecukupan dialisis seorang pasien meleset berulang kali/');

        $this->dialisis->terminate($sesi, '   ');
    }

    #[Test]
    public function basis_data_menolak_dihentikan_tanpa_alasan(): void
    {
        $sesi = $this->sesi();

        $this->expectException(QueryException::class);

        DialysisSession::query()->whereKey($sesi->id)->update([
            'status' => DialysisSession::DIHENTIKAN, 'ended_at' => now(),
        ]);
    }

    // ============================================== ultrafiltrasi

    #[Test]
    public function target_ultrafiltrasi_resep_bisa_berbeda_dari_hitungan_berat(): void
    {
        $sesi = $this->sesi([
            'dry_weight_kg' => 60.0,
            'pre_weight_kg' => 63.5,
            'target_ultrafiltration_l' => 2.50,
        ]);

        // Hitungan berat 3.5 L, tapi dokter meresepkan 2.5 L karena pasien
        // tidak tahan penarikan sebanyak itu. Selisihnya yang perlu
        // terlihat, dan itulah alasan keduanya disimpan.
        $this->assertSame(3.5, $sesi->arithmeticTargetL());
        $this->assertSame(-1.0, $sesi->targetDeviationL());
    }

    #[Test]
    public function penurunan_berat_jadi_pembanding_bagi_ultrafiltrasi_mesin(): void
    {
        $sesi = $this->sesi(['pre_weight_kg' => 63.5]);
        $selesai = $this->dialisis->finish($sesi, [
            'post_weight_kg' => 61.0,
            'achieved_ultrafiltration_l' => 2.40,
        ]);

        // 2.5 kg turun berbanding 2.4 L ditarik: berdekatan, jadi keduanya
        // saling meneguhkan. Selisih besar berarti salah satunya keliru.
        $this->assertSame(2.5, $selesai->weightLossKg());
        $this->assertEqualsWithDelta(2.4, (float) $selesai->achieved_ultrafiltration_l, 0.001);
    }

    // ============================================== parameter mesin

    #[Test]
    public function pembacaan_mesin_dicatat_terpisah_dari_tanda_vital_pasien(): void
    {
        $sesi = $this->sesi();

        $bacaan = $this->dialisis->observe($sesi, [
            'blood_flow_ml_min' => 250,
            'arterial_pressure_mmhg' => -120,
            'venous_pressure_mmhg' => 150,
            'transmembrane_pressure_mmhg' => 90,
        ], $this->perawatHd);

        // Tekanan arteri pada sisi hisap memang bernilai minus — kolomnya
        // harus bisa menampungnya.
        $this->assertSame(-120, $bacaan->arterial_pressure_mmhg);

        // catatan_observasi_hemodialisa Khanza mencampur ukuran mesin dan
        // ukuran pasien; tensi/nadi/suhu/SpO2 di sini tidak punya kolom.
        foreach (['tensi', 'blood_pressure', 'pulse', 'temperature', 'spo2'] as $kolom) {
            $this->assertArrayNotHasKey($kolom, $bacaan->getAttributes());
        }
    }

    #[Test]
    public function tekanan_vena_tinggi_bisa_ditandai(): void
    {
        $sesi = $this->sesi();

        $aman = $this->dialisis->observe($sesi, [
            'venous_pressure_mmhg' => 150, 'observed_at' => now()->subMinutes(30),
        ], $this->perawatHd);
        $tinggi = $this->dialisis->observe($sesi, [
            'venous_pressure_mmhg' => 250, 'observed_at' => now(),
        ], $this->perawatHd);

        $this->assertFalse($aman->hasHighVenousPressure());
        $this->assertTrue($tinggi->hasHighVenousPressure());
    }

    #[Test]
    public function pembacaan_pada_waktu_yang_sama_memperbarui_bukan_menggandakan(): void
    {
        $sesi = $this->sesi();
        $waktu = now()->startOfMinute();

        $this->dialisis->observe($sesi, ['blood_flow_ml_min' => 200, 'observed_at' => $waktu], $this->perawatHd);
        $this->dialisis->observe($sesi, ['blood_flow_ml_min' => 250, 'observed_at' => $waktu], $this->perawatHd);

        $this->assertCount(1, $sesi->refresh()->observations);
        $this->assertSame(250, $sesi->observations->first()->blood_flow_ml_min);
    }

    #[Test]
    public function sesi_yang_sudah_selesai_tidak_bisa_ditambahi_pembacaan(): void
    {
        $sesi = $this->sesi();
        $this->dialisis->finish($sesi);

        $this->expectException(ClinicalException::class);
        $this->expectExceptionMessageMatches('/sudah tidak berjalan/');

        $this->dialisis->observe($sesi->refresh(), ['blood_flow_ml_min' => 200], $this->perawatHd);
    }

    // ============================================== balans cairan

    #[Test]
    public function jenis_cairan_khas_hd_sudah_ada_di_master_yang_sama(): void
    {
        // catatan_cairan_hemodialisa Khanza adalah tabel balans KEDUA
        // dengan kolom per jenis cairannya sendiri. Di sini jenis khas HD
        // tinggal baris pada master yang sama dengan bangsal, jadi balans
        // pasien yang sama pada hari yang sama tetap bisa dijumlahkan.
        $khasHd = FluidItem::query()->where('care_context', 'hemodialisa')->pluck('code')->all();

        $this->assertContains('sisa-priming', $khasHd);
        $this->assertContains('wash-out', $khasHd);
        $this->assertContains('ultrafiltrasi', $khasHd);

        $this->assertSame(
            FluidItem::KELUAR,
            FluidItem::query()->where('code', 'ultrafiltrasi')->value('direction')
        );
    }

    // ---------------------------------------------------------------- fixture

    private function sesi(array $data = []): DialysisSession
    {
        return $this->dialisis->start($this->siapPeriksa()->id, $data, $this->perawatHd);
    }

    private function siapPeriksa(): Registration
    {
        $kunjungan = $this->daftarkan();

        $this->dialisis->recordSerology($kunjungan->patient_id, [
            'hbsag' => DialysisSerology::NON_REAKTIF,
            'anti_hcv' => DialysisSerology::NON_REAKTIF,
            'anti_hiv' => DialysisSerology::NON_REAKTIF,
        ], $this->perawatHd);

        return $kunjungan;
    }

    private function daftarkan(?object $pasien = null): Registration
    {
        static $urut = 0;
        $urut++;

        $pasien ??= app(PatientRegistry::class)->register([
            'name' => 'Pasien HD '.$urut, 'sex' => 'L', 'birth_date' => '1966-06-06',
        ]);

        $kunjungan = app(RegistrationService::class)->register(
            patientId: $pasien->id,
            unitId: Unit::query()->where('code', 'POL-UMUM')->value('id'),
            payerId: Payer::query()->where('code', 'UMUM')->value('id'),
            practitionerId: Practitioner::query()->where('is_active', true)->value('id'),
            serviceDate: now()->subDays($urut),
        );

        $kunjungan->patient = $pasien;

        return $kunjungan;
    }
}
