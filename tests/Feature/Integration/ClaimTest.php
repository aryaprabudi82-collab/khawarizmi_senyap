<?php

namespace Tests\Feature\Integration;

use App\Modules\Catalog\Models\Payer;
use App\Modules\Clinical\Database\Seeders\DiagnosisCodeSeeder;
use App\Modules\Encounter\Models\Registration;
use App\Modules\Encounter\Services\RegistrationService;
use App\Modules\Identity\Services\PatientRegistry;
use App\Modules\Integration\Models\Claim;
use App\Modules\Integration\Services\Bpjs\ClaimClient;
use App\Modules\Integration\Services\Bpjs\ClaimService;
use App\Modules\Integration\Services\Bpjs\FakeClaimClient;
use App\Modules\Integration\Services\IntegrationException;
use App\Modules\Organization\Models\Practitioner;
use App\Modules\Organization\Models\Unit;
use App\Modules\Platform\Database\Seeders\PermissionCatalogSeeder;
use App\Modules\Platform\Database\Seeders\RoleSeeder;
use App\Modules\Platform\Models\User;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Klaim INA-CBG & monitoring klaim (domain L item C).
 *
 * Yang paling perlu dikunci: kode CBG dan tarifnya SELALU berasal dari
 * jawaban grouper, tidak pernah dihitung di sisi kita — menghitungnya
 * sendiri berarti mengarang tarif yang akan dibayarkan negara. Dan isi
 * klaim dibekukan saat dikirim, supaya koreksi rekam medis tidak diam-diam
 * mengubah klaim yang sudah diverifikasi.
 */
class ClaimTest extends TestCase
{
    use RefreshDatabase;

    private ClaimService $klaim;
    private RegistrationService $registrations;
    private User $petugas;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([
            PermissionCatalogSeeder::class, RoleSeeder::class,
            ReferenceDataSeeder::class, DiagnosisCodeSeeder::class,
        ]);

        $this->app->bind(ClaimClient::class, fn () => new FakeClaimClient());

        $this->klaim = app(ClaimService::class);
        $this->registrations = app(RegistrationService::class);

        $this->petugas = User::query()->create([
            'username' => 'uji-integrasi-klaim', 'name' => 'Petugas Klaim',
            'password' => 'password', 'is_active' => true,
        ]);
    }

    // ------------------------------------------------------------- penyusunan

    #[Test]
    public function klaim_disusun_dari_data_kunjungan_yang_ada(): void
    {
        $r = $this->daftarkanBpjs();

        $klaim = $this->klaim->assemble($r->id);

        $this->assertSame(Claim::DRAF, $klaim->status);
        $this->assertSame($r->registration_number, $klaim->registration_number);
        $this->assertStringStartsWith('KLM', $klaim->claim_number);
        $this->assertNull($klaim->cbg_code, 'Belum dikirim, jadi belum ada kode CBG');
    }

    /** Dua klaim aktif atas kunjungan yang sama akan dibayar dua kali atau ditolak dua-duanya. */
    #[Test]
    public function satu_kunjungan_tidak_boleh_punya_dua_klaim_aktif(): void
    {
        $r = $this->daftarkanBpjs();
        $this->klaim->assemble($r->id);

        $this->expectException(IntegrationException::class);
        $this->expectExceptionMessage('sudah punya klaim');

        $this->klaim->assemble($r->id);
    }

    #[Test]
    public function klaim_yang_dibatalkan_boleh_diganti(): void
    {
        $r = $this->daftarkanBpjs();
        $this->klaim->cancel($this->klaim->assemble($r->id), 'salah kunjungan');

        $baru = $this->klaim->assemble($r->id);

        $this->assertSame(Claim::DRAF, $baru->status);
    }

    #[Test]
    public function kunjungan_yang_tidak_ada_ditolak(): void
    {
        $this->expectException(IntegrationException::class);
        $this->expectExceptionMessage('tidak ditemukan');

        $this->klaim->assemble(999999);
    }

    // -------------------------------------------------- kode CBG dari grouper

    /**
     * INTI ITEM INI: kode CBG dan tarifnya diterima dari grouper, bukan
     * dihitung. Klien palsu sengaja mengembalikan kode berawalan "X-"
     * supaya tidak pernah tertukar dengan kode CBG asli.
     */
    #[Test]
    public function kode_cbg_dan_tarif_berasal_dari_grouper(): void
    {
        $klaim = $this->klaimSiapKirim();

        $klaim = $this->klaim->submit($klaim, $this->petugas->id);

        $this->assertSame(Claim::TERKIRIM, $klaim->status);
        $this->assertStringStartsWith('X-', $klaim->cbg_code, 'Kode dari grouper, bukan dihitung sendiri');
        $this->assertNotNull($klaim->cbg_tariff);
        $this->assertNotNull($klaim->grouper_response, 'Jawaban grouper disimpan utuh');
    }

    #[Test]
    public function klaim_tanpa_diagnosis_ditolak_sebelum_dikirim(): void
    {
        $r = $this->daftarkanBpjs();
        $this->terbitkanSep($r);
        $klaim = $this->klaim->assemble($r->id);

        $this->expectException(IntegrationException::class);
        $this->expectExceptionMessage('tanpa diagnosis');

        $this->klaim->submit($klaim);
    }

    #[Test]
    public function klaim_bpjs_tanpa_sep_ditolak(): void
    {
        $r = $this->daftarkanBpjs();
        $this->diagnosa($r, 'J06.9');
        $klaim = $this->klaim->assemble($r->id);

        $this->expectException(IntegrationException::class);
        $this->expectExceptionMessage('harus punya SEP');

        $this->klaim->submit($klaim);
    }

    /** Grouper gangguan mengembalikan klaim, bukan menghilangkannya. */
    #[Test]
    public function grouper_gangguan_mengembalikan_klaim_untuk_dikirim_ulang(): void
    {
        $r = $this->daftarkanBpjs();
        $this->diagnosa($r, 'J06.9');
        $this->terbitkanSep($r, '8000-GANGGUAN');

        $klaim = $this->klaim->submit($this->klaim->assemble($r->id));

        $this->assertSame(Claim::DIKEMBALIKAN, $klaim->status);
        $this->assertNull($klaim->cbg_code, 'Tidak ada kode CBG yang dikarang saat grouper gagal');
        $this->assertStringContainsString('gangguan', $klaim->response_message);

        // Yang dikembalikan boleh dikirim ulang.
        $ulang = $this->klaim->submit($klaim->refresh());
        $this->assertContains($ulang->status, [Claim::TERKIRIM, Claim::DIKEMBALIKAN]);
    }

    /**
     * Isi klaim DIBEKUKAN saat dikirim: koreksi rekam medis setelahnya
     * tidak boleh mengubah klaim yang sudah terkirim.
     */
    #[Test]
    public function isi_klaim_dibekukan_saat_dikirim(): void
    {
        $r = $this->daftarkanBpjs();
        $this->diagnosa($r, 'J06.9');
        $this->terbitkanSep($r);

        $klaim = $this->klaim->submit($this->klaim->assemble($r->id));

        $this->assertCount(1, $klaim->diagnoses);

        // Rekam medis dikoreksi setelah klaim terkirim. Diagnosis kedua
        // harus SEKUNDER: satu kunjungan cuma boleh punya satu diagnosis
        // utama, dijaga indeks unik diagnoses_single_primary.
        $this->diagnosa($r, 'I10', 'sekunder');

        $this->assertCount(1, $klaim->refresh()->diagnoses,
            'Klaim yang sudah terkirim tidak ikut berubah oleh koreksi rekam medis');
    }

    #[Test]
    public function klaim_terkirim_tidak_bisa_dikirim_ulang(): void
    {
        $klaim = $this->klaim->submit($this->klaimSiapKirim());

        $this->expectException(IntegrationException::class);
        $this->expectExceptionMessage('tidak bisa dikirim');

        $this->klaim->submit($klaim);
    }

    #[Test]
    public function klaim_terverifikasi_tidak_bisa_dibatalkan(): void
    {
        $klaim = $this->klaim->submit($this->klaimSiapKirim());
        $klaim->update(['status' => Claim::TERVERIFIKASI]);

        $this->expectException(IntegrationException::class);
        $this->expectExceptionMessage('sudah diverifikasi');

        $this->klaim->cancel($klaim->refresh(), 'coba-coba');
    }

    // ------------------------------------------------------------- margin

    /**
     * Selisih tarif CBG terhadap biaya rumah sakit adalah angka yang
     * menentukan klaim menguntungkan atau merugikan, dan yang paling
     * sering tidak terlihat sampai kerugiannya menumpuk.
     */
    #[Test]
    public function selisih_tarif_cbg_terhadap_biaya_rs_dihitung(): void
    {
        $klaim = $this->klaim->submit($this->klaimSiapKirim());

        $this->assertNotNull($klaim->margin());
        $this->assertSame(
            round((float) $klaim->cbg_tariff - (float) $klaim->hospital_charge, 2),
            $klaim->margin()
        );

        $ringkas = $this->klaim->marginSummary(now()->toDateString(), now()->toDateString());
        $this->assertSame(1, $ringkas->klaim);
    }

    #[Test]
    public function klaim_yang_belum_dikirim_tidak_punya_margin(): void
    {
        $klaim = $this->klaim->assemble($this->daftarkanBpjs()->id);

        $this->assertNull($klaim->margin(), 'Tanpa tarif dari grouper, margin tidak bisa dihitung');
        $this->assertFalse($klaim->isLoss());
    }

    // -------------------------------------------------------------- monitoring

    /**
     * Monitoring TIDAK mengubah status klaim kita — supaya "belum kami
     * kirim" tetap bisa dibedakan dari "sudah dikirim tapi belum
     * diverifikasi BPJS".
     */
    #[Test]
    public function monitoring_tidak_mengubah_status_klaim_kita(): void
    {
        $klaim = $this->klaim->submit($this->klaimSiapKirim());

        $this->klaim->monitor('rs', now()->startOfMonth()->toDateString(), now()->toDateString());

        $this->assertSame(Claim::TERKIRIM, $klaim->refresh()->status,
            'Status pengiriman kita tetap, jawaban BPJS cuma disimpan sebagai salinan');
    }

    #[Test]
    public function monitoring_menyimpan_jawaban_bpjs_apa_adanya(): void
    {
        $mon = $this->klaim->monitor('rs', now()->startOfMonth()->toDateString(), now()->toDateString());

        $this->assertTrue($mon->success);
        $this->assertSame(2, $mon->claim_count);
        $this->assertSame('4000000.00', $mon->total_tariff);
        $this->assertNotNull($mon->raw_response);
    }

    #[Test]
    public function lingkup_monitoring_di_luar_daftar_ditolak(): void
    {
        $this->expectException(IntegrationException::class);
        $this->expectExceptionMessage('tidak dikenal');

        $this->klaim->monitor('laboratorium', '2026-09-01', '2026-09-30');
    }

    // ------------------------------------------------------------------ bantu

    private function klaimSiapKirim(): Claim
    {
        $r = $this->daftarkanBpjs();
        $this->diagnosa($r, 'J06.9');
        $this->terbitkanSep($r);

        return $this->klaim->assemble($r->id);
    }

    private function daftarkanBpjs(): Registration
    {
        static $urut = 0;
        $urut++;

        $pasien = app(PatientRegistry::class)->register([
            'name' => 'Pasien Klaim ' . $urut, 'sex' => 'L', 'birth_date' => '1990-01-01',
        ]);

        return $this->registrations->register(
            patientId: $pasien->id,
            unitId: Unit::query()->where('code', 'POL-UMUM')->firstOrFail()->id,
            payerId: Payer::query()->where('code', 'BPJS')->value('id'),
            practitionerId: Practitioner::query()->where('is_active', true)->value('id'),
        );
    }

    private function diagnosa(Registration $r, string $kode, string $rank = 'utama'): void
    {
        DB::table('clinical.diagnoses')->insert([
            'registration_id' => $r->id,
            'patient_id' => $r->patient_id,
            'registration_number' => $r->registration_number,
            'code' => $kode,
            'display' => DB::table('clinical.diagnosis_codes')->where('code', $kode)->value('display') ?? $kode,
            'rank' => $rank,
            'certainty' => 'definitif',
            'diagnosed_at' => now(),
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function terbitkanSep(Registration $r, string $nomor = 'SEP-001'): void
    {
        static $urut = 0;
        $urut++;

        DB::table('integration.bpjs_sep')->insert([
            'registration_id' => $r->id,
            'registration_number' => $r->registration_number,
            'no_kartu' => '000123456789' . $urut,
            'sep_number' => $nomor . '-' . $urut,
            'poli_tujuan' => 'INT',
            'diagnosa_awal' => 'Z00.0',
            'jenis_pelayanan' => '2',
            'status' => 'terbit',
            'requested_at' => now(),
            'issued_at' => now(),
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }
}
