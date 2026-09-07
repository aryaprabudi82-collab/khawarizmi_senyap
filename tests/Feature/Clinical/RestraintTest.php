<?php

namespace Tests\Feature\Clinical;

use App\Modules\Catalog\Models\Payer;
use App\Modules\Clinical\Models\RestraintEpisode;
use App\Modules\Clinical\Models\RestraintReview;
use App\Modules\Clinical\Services\ClinicalException;
use App\Modules\Clinical\Services\RestraintService;
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
 * Restrain (domain M item T).
 *
 * Restrain adalah tindakan paling membatasi hak pasien yang boleh
 * dilakukan rumah sakit, dan justru di situ catatan Khanza paling
 * kurang. Yang dikunci:
 *
 * 1. PERINTAH DOKTER WAJIB — Khanza cuma mencatat perawat pengisi.
 * 2. JAM MULAI DAN LEPAS DICATAT, lamanya dihitung — Khanza hanya
 *    punya tanggal penilaian.
 * 3. PENILAIAN ULANG MENEMPEL PADA EPISODENYA dan ditagih.
 * 4. MENERUSKAN PENGEKANGAN WAJIB BERALASAN, MELEPASKANNYA TIDAK.
 * 5. PERINTAH KEDALUWARSA MENAGIH, TIDAK MELEPAS PASIEN.
 */
class RestraintTest extends TestCase
{
    use RefreshDatabase;

    private RestraintService $restrain;

    private User $perawat;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([PermissionCatalogSeeder::class, RoleSeeder::class, ReferenceDataSeeder::class]);

        $this->restrain = app(RestraintService::class);

        $this->perawat = User::query()->create([
            'username' => 'uji-restrain', 'name' => 'Ns. Jaga Malam',
            'password' => 'password', 'is_active' => true,
        ]);
    }

    // ============================================== perintah dokter

    #[Test]
    public function restrain_tanpa_perintah_dokter_ditolak(): void
    {
        $kunjungan = $this->daftarkan();

        $this->expectException(ClinicalException::class);
        $this->expectExceptionMessageMatches('/pengekangan tanpa dasar/');

        $this->restrain->start($kunjungan->id, [
            'indication' => 'membahayakan-diri',
            'observed_behaviour' => 'Pasien mencabut infus berulang kali.',
            'restraint_types' => ['pergelangan-tangan-kanan'],
        ], $this->perawat);
    }

    #[Test]
    public function episode_mencatat_pemerintah_dan_masa_berlaku_perintahnya(): void
    {
        $episode = $this->pasang();

        // Khanza hanya mencatat nip perawat yang mengisi.
        $this->assertSame('dr. Penanggung Jawab, Sp.PD', $episode->ordered_by_name);
        $this->assertNotNull($episode->ordered_at);
        $this->assertSame(
            $episode->ordered_at->copy()->addHours(24)->toDateTimeString(),
            $episode->order_expires_at->toDateTimeString()
        );
    }

    #[Test]
    public function restrain_tidak_boleh_dipasang_sebelum_diperintahkan(): void
    {
        $kunjungan = $this->daftarkan();

        $this->expectException(ClinicalException::class);
        $this->expectExceptionMessageMatches('/waktu perintahnya yang perlu dicatat apa adanya/');

        $this->restrain->start($kunjungan->id, [
            'ordered_by_name' => 'dr. Jaga',
            'ordered_at' => now(),
            'started_at' => now()->subHours(2),
            'indication' => 'membahayakan-diri',
            'observed_behaviour' => 'Gelisah berat.',
            'restraint_types' => ['badan'],
        ], $this->perawat);
    }

    #[Test]
    public function basis_data_menolak_mulai_sebelum_perintah(): void
    {
        $episode = $this->pasang();

        $this->expectException(QueryException::class);

        RestraintEpisode::query()->whereKey($episode->id)
            ->update(['started_at' => $episode->ordered_at->copy()->subHour()]);
    }

    #[Test]
    public function perintah_kedaluwarsa_ditagih_bukan_melepas_pasien(): void
    {
        $episode = $this->pasang(['ordered_at' => now()->subHours(30)]);

        // Melepas pasien yang masih berbahaya karena perintahnya
        // kedaluwarsa jelas lebih berbahaya daripada perintah yang telat
        // diperbarui.
        $this->assertTrue($episode->hasExpiredOrder());
        $this->assertTrue($episode->isRunning());

        $tertunggak = $this->restrain->withExpiredOrder()->pluck('id')->all();
        $this->assertContains($episode->id, $tertunggak);
    }

    #[Test]
    public function perintah_bisa_diperbarui_dan_tenggatnya_bergeser(): void
    {
        $episode = $this->pasang(['ordered_at' => now()->subHours(30)]);

        $diperbarui = $this->restrain->renewOrder($episode, 'dr. Jaga Pagi, Sp.KJ');

        $this->assertFalse($diperbarui->hasExpiredOrder());
        $this->assertSame('dr. Jaga Pagi, Sp.KJ', $diperbarui->ordered_by_name);
    }

    // ============================================== durasi & titik

    #[Test]
    public function lama_pengekangan_dihitung_dari_jam_mulai_dan_lepas(): void
    {
        $episode = $this->pasang(['started_at' => now()->subHours(5)]);

        $dilepas = $this->restrain->release($episode, 'Pasien tenang, orientasi kembali baik.');

        // Khanza hanya punya `tanggal` penilaian: pertanyaan pokok setiap
        // peninjauan restrain tidak bisa dijawab sama sekali.
        $this->assertSame(300, $dilepas->durationMinutes());
        $this->assertArrayNotHasKey('duration_minutes', $dilepas->getAttributes());
    }

    #[Test]
    public function jumlah_titik_pengekangan_terbaca(): void
    {
        $episode = $this->pasang([
            'restraint_types' => [
                'pergelangan-tangan-kanan', 'pergelangan-tangan-kiri',
                'pergelangan-kaki-kanan', 'pergelangan-kaki-kiri',
            ],
        ]);

        // Enum satu pilihan Khanza membuat pasien yang diikat empat titik
        // dan pasien yang diikat satu titik terbaca sama.
        $this->assertSame(4, $episode->restraintPointCount());
    }

    #[Test]
    public function restrain_tanpa_titik_maupun_farmakologi_ditolak(): void
    {
        $kunjungan = $this->daftarkan();

        $this->expectException(ClinicalException::class);
        $this->expectExceptionMessageMatches('/Restrain tanpa keduanya bukan restrain/');

        $this->restrain->start($kunjungan->id, [
            'ordered_by_name' => 'dr. Jaga',
            'indication' => 'membahayakan-diri',
            'observed_behaviour' => 'Gelisah.',
            'restraint_types' => [],
        ], $this->perawat);
    }

    #[Test]
    public function restrain_farmakologi_saja_tetap_sah(): void
    {
        $kunjungan = $this->daftarkan();

        $episode = $this->restrain->start($kunjungan->id, [
            'ordered_by_name' => 'dr. Jaga, Sp.KJ',
            'indication' => 'membahayakan-orang-lain',
            'observed_behaviour' => 'Memukul petugas.',
            'restraint_types' => [],
            'pharmacological_restraint' => 'Haloperidol 5 mg IM',
        ], $this->perawat);

        $this->assertSame(0, $episode->restraintPointCount());
        $this->assertNotNull($episode->pharmacological_restraint);
    }

    #[Test]
    public function jenis_restrain_di_luar_kosakata_ditolak(): void
    {
        $kunjungan = $this->daftarkan();

        $this->expectException(ClinicalException::class);
        $this->expectExceptionMessageMatches('/tidak dikenali: diikat-tali/');

        $this->restrain->start($kunjungan->id, [
            'ordered_by_name' => 'dr. Jaga',
            'indication' => 'membahayakan-diri',
            'observed_behaviour' => 'Gelisah.',
            'restraint_types' => ['badan', 'diikat-tali'],
        ], $this->perawat);
    }

    #[Test]
    public function satu_kunjungan_tidak_punya_dua_episode_berjalan(): void
    {
        $kunjungan = $this->daftarkan();
        $this->pasang([], $kunjungan);

        $this->expectException(ClinicalException::class);
        $this->expectExceptionMessageMatches('/bukan sebagai episode kedua/');

        $this->pasang([], $kunjungan);
    }

    #[Test]
    public function episode_yang_sudah_dilepas_membuka_jalan_episode_baru(): void
    {
        $kunjungan = $this->daftarkan();
        $pertama = $this->pasang([], $kunjungan);

        $this->restrain->release($pertama, 'Pasien tenang.');
        $kedua = $this->pasang([], $kunjungan);

        $this->assertNotSame($pertama->id, $kedua->id);
    }

    // ============================================== upaya lebih ringan

    #[Test]
    public function upaya_yang_lebih_ringan_tercatat_sebagai_daftar(): void
    {
        $episode = $this->pasang([
            'alternatives_tried' => ['pendekatan-verbal', 'pendampingan-keluarga', 'peninjauan-obat'],
        ]);

        $this->assertCount(3, $episode->alternatives_tried);
        $this->assertFalse($episode->hadNoAlternativesTried());
    }

    #[Test]
    public function restrain_tanpa_upaya_lebih_ringan_tetap_dicatat_tapi_jadi_temuan(): void
    {
        // Kegawatan nyata memang ada; yang dilarang bukan pencatatannya
        // melainkan menyembunyikannya.
        $episode = $this->pasang(['alternatives_tried' => []]);

        $this->assertTrue($episode->hadNoAlternativesTried());

        $temuan = $this->restrain
            ->withoutAlternativesTried(now()->subDay()->toDateTimeString(), now()->addDay()->toDateTimeString())
            ->pluck('id')->all();

        $this->assertContains($episode->id, $temuan);
    }

    #[Test]
    public function upaya_di_luar_kosakata_ditolak(): void
    {
        $kunjungan = $this->daftarkan();

        $this->expectException(ClinicalException::class);
        $this->expectExceptionMessageMatches('/tidak dikenali: dibentak/');

        $this->pasang(['alternatives_tried' => ['pendekatan-verbal', 'dibentak']], $kunjungan);
    }

    // ============================================== penilaian ulang

    #[Test]
    public function penilaian_ulang_menempel_pada_episodenya(): void
    {
        $episode = $this->pasang();

        $tinjau = $this->restrain->review($episode, [
            'circulation' => 'baik', 'skin_condition' => 'utuh',
            'position_changed' => true, 'basic_needs_met' => true,
            'still_needed' => true, 'reason' => 'Masih gelisah dan mencoba mencabut infus.',
        ], $this->perawat);

        // Baris pengkajian_restrain Khanza boleh berulang, tapi tidak ada
        // yang menautkannya ke episode yang sama.
        $this->assertSame($episode->id, $tinjau->episode_id);
        $this->assertCount(1, $episode->refresh()->reviews);
    }

    #[Test]
    public function meneruskan_pengekangan_wajib_beralasan(): void
    {
        $episode = $this->pasang();

        $this->expectException(ClinicalException::class);
        $this->expectExceptionMessageMatches('/bukan membebaskannya/');

        $this->restrain->review($episode, ['still_needed' => true], $this->perawat);
    }

    #[Test]
    public function melepaskan_tidak_menuntut_alasan_pada_penilaian_ulang(): void
    {
        $episode = $this->pasang();

        // Bebannya sengaja tidak simetris.
        $tinjau = $this->restrain->review($episode, ['still_needed' => false], $this->perawat);

        $this->assertFalse($tinjau->still_needed);
        $this->assertNull($tinjau->reason);
    }

    #[Test]
    public function basis_data_menolak_meneruskan_tanpa_alasan(): void
    {
        $episode = $this->pasang();
        $tinjau = $this->restrain->review($episode, [
            'still_needed' => true, 'reason' => 'Masih berbahaya.',
        ], $this->perawat);

        $this->expectException(QueryException::class);

        RestraintReview::query()->whereKey($tinjau->id)->update(['reason' => '']);
    }

    #[Test]
    public function keputusan_masih_perlu_wajib_dijawab(): void
    {
        $episode = $this->pasang();

        $this->expectException(ClinicalException::class);
        $this->expectExceptionMessageMatches('/peninjauannya tidak menghasilkan apa pun/');

        $this->restrain->review($episode, ['circulation' => 'baik'], $this->perawat);
    }

    #[Test]
    public function episode_yang_lewat_tenggat_tinjau_bisa_ditagih(): void
    {
        $telat = $this->pasang(['started_at' => now()->subHours(4)]);
        $baru = $this->pasang([], $this->daftarkan());

        $tertunggak = $this->restrain->reviewOverdue()->pluck('id')->all();

        $this->assertContains($telat->id, $tertunggak);
        $this->assertNotContains($baru->id, $tertunggak);
    }

    #[Test]
    public function penilaian_ulang_menggeser_tenggat_berikutnya(): void
    {
        $episode = $this->pasang(['started_at' => now()->subHours(4)]);
        $this->assertTrue($episode->isReviewOverdue());

        $this->restrain->review($episode, [
            'still_needed' => true, 'reason' => 'Masih gelisah.',
        ], $this->perawat);

        $this->assertFalse($episode->refresh()->isReviewOverdue());
    }

    #[Test]
    public function cedera_akibat_pengekangan_bisa_disebutkan(): void
    {
        $episode = $this->pasang();

        $this->restrain->review($episode, [
            'circulation' => 'menurun', 'skin_condition' => 'lecet',
            'still_needed' => false,
        ], $this->perawat);

        $cedera = $this->restrain
            ->withInjury(now()->subDay()->toDateTimeString(), now()->addDay()->toDateTimeString())
            ->pluck('id')->all();

        $this->assertContains($episode->id, $cedera);
    }

    // ============================================== pelepasan

    #[Test]
    public function pelepasan_wajib_menyebut_alasannya(): void
    {
        $episode = $this->pasang();

        $this->expectException(ClinicalException::class);
        $this->expectExceptionMessageMatches('/bagian dari pembenarannya/');

        $this->restrain->release($episode, '   ');
    }

    #[Test]
    public function basis_data_menolak_dilepas_tanpa_alasan(): void
    {
        $episode = $this->pasang();

        $this->expectException(QueryException::class);

        RestraintEpisode::query()->whereKey($episode->id)->update([
            'status' => RestraintEpisode::DILEPAS, 'released_at' => now(),
        ]);
    }

    #[Test]
    public function episode_yang_dilepas_tidak_bisa_ditinjau_lagi(): void
    {
        $episode = $this->pasang();
        $this->restrain->release($episode, 'Pasien tenang.');

        $this->expectException(ClinicalException::class);
        $this->expectExceptionMessageMatches('/sudah tidak berjalan/');

        $this->restrain->review($episode->refresh(), ['still_needed' => false], $this->perawat);
    }

    // ---------------------------------------------------------------- fixture

    private function pasang(array $data = [], ?Registration $kunjungan = null): RestraintEpisode
    {
        $kunjungan ??= $this->daftarkan();

        // Memundurkan jam mulai berarti memundurkan perintahnya juga:
        // restrain tidak bisa dipasang sebelum diperintahkan, dan itu
        // aturan yang memang ditegakkan service.
        if (isset($data['started_at']) && ! isset($data['ordered_at'])) {
            $data['ordered_at'] = $data['started_at'];
        }

        return $this->restrain->start($kunjungan->id, $data + [
            'ordered_by_name' => 'dr. Penanggung Jawab, Sp.PD',
            'indication' => 'membahayakan-diri',
            'observed_behaviour' => 'Pasien delirium, mencabut infus dan NGT berulang kali.',
            'alternatives_tried' => ['pendekatan-verbal', 'pendampingan-keluarga'],
            'restraint_types' => ['pergelangan-tangan-kanan', 'pergelangan-tangan-kiri'],
            'family_informed' => true,
            'consenting_family_name' => 'Sri Wahyuni',
            'consenting_family_relation' => 'Istri',
        ], $this->perawat);
    }

    private function daftarkan(): Registration
    {
        static $urut = 0;
        $urut++;

        $pasien = app(PatientRegistry::class)->register([
            'name' => 'Pasien Restrain '.$urut, 'sex' => 'L', 'birth_date' => '1948-08-17',
        ]);

        return app(RegistrationService::class)->register(
            patientId: $pasien->id,
            unitId: Unit::query()->where('code', 'POL-UMUM')->value('id'),
            payerId: Payer::query()->where('code', 'UMUM')->value('id'),
            practitionerId: Practitioner::query()->where('is_active', true)->value('id'),
        );
    }
}
