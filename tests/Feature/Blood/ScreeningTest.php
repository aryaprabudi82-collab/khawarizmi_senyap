<?php

namespace Tests\Feature\Blood;

use App\Modules\Blood\Models\BloodUnit;
use App\Modules\Blood\Models\Donor;
use App\Modules\Blood\Models\LookbackInvestigation;
use App\Modules\Blood\Models\UnitScreening;
use App\Modules\Blood\Services\BloodException;
use App\Modules\Blood\Services\BloodUnitService;
use App\Modules\Blood\Services\DonorService;
use App\Modules\Blood\Services\ScreeningService;
use App\Modules\Blood\Services\TransfusionService;
use App\Modules\Platform\Database\Seeders\PermissionCatalogSeeder;
use App\Modules\Platform\Database\Seeders\RoleSeeder;
use App\Modules\Platform\Models\Role;
use App\Modules\Platform\Models\User;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Skrining IMLTD & look-back (domain N item A).
 *
 * DI SINI YANG KURANG ADALAH KITA, BUKAN KHANZA: utd_donor mencatat
 * lima hasil skrining per donasi, sementara release() di sini
 * memindahkan unit ke "tersedia" tanpa bukti skrining apa pun.
 *
 * Yang dikunci:
 *
 * 1. KELIMA PEMERIKSAAN WAJIB, bukan sebagian.
 * 2. SATU HASIL REAKTIF MENOLAK KANTONGNYA.
 * 3. HASIL MERAGUKAN MENAHAN, TIDAK MENOLAK.
 * 4. DONOR REAKTIF MEMICU PENCEKALAN DAN LOOK-BACK.
 * 5. LOOK-BACK MENELUSURI BERJENJANG sampai komponen hasil pemisahan.
 */
class ScreeningTest extends TestCase
{
    use RefreshDatabase;

    private ScreeningService $skrining;

    private BloodUnitService $units;

    private DonorService $donors;

    private TransfusionService $transfusi;

    private User $petugas;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([PermissionCatalogSeeder::class, RoleSeeder::class, ReferenceDataSeeder::class]);

        $this->skrining = app(ScreeningService::class);
        $this->units = app(BloodUnitService::class);
        $this->donors = app(DonorService::class);
        $this->transfusi = app(TransfusionService::class);

        $this->petugas = User::query()->create([
            'username' => 'uji-skrining-utd', 'name' => 'Analis UTD',
            'password' => 'password', 'is_active' => true,
        ]);
        $this->petugas->roles()->attach(Role::query()->where('code', 'petugas-utd')->firstOrFail());
    }

    // ============================================== kelima wajib

    #[Test]
    public function unit_tidak_bisa_dirilis_tanpa_hasil_skrining(): void
    {
        $unit = $this->ambilUnit();

        // Inilah lubang yang ditutup item ini: sebelumnya unit bisa
        // langsung dirilis ke "tersedia" tanpa bukti apa pun.
        $this->expectException(BloodException::class);
        $this->expectExceptionMessageMatches('/pernyataan tanpa dasar/');

        $this->skrining->releaseAfterScreening($unit, $this->petugas);
    }

    #[Test]
    public function skrining_dengan_pemeriksaan_kurang_ditolak(): void
    {
        $unit = $this->ambilUnit();

        $this->expectException(BloodException::class);
        $this->expectExceptionMessageMatches('/Malaria belum diisi/');

        $this->skrining->screen($unit, [
            'hbsag' => UnitScreening::NON_REAKTIF,
            'anti_hcv' => UnitScreening::NON_REAKTIF,
            'anti_hiv' => UnitScreening::NON_REAKTIF,
            'syphilis' => UnitScreening::NON_REAKTIF,
        ], $this->petugas);
    }

    #[Test]
    public function skrining_bersih_membuka_jalan_rilis(): void
    {
        $unit = $this->ambilUnit();

        $skrining = $this->skrining->screen($unit, $this->bersih(), $this->petugas, [
            'method' => 'CHLIA',
        ]);

        $this->assertTrue($skrining->isClear());

        $dirilis = $this->skrining->releaseAfterScreening($unit->refresh(), $this->petugas);

        $this->assertSame(BloodUnit::STATUS_TERSEDIA, $dirilis->status);
    }

    #[Test]
    public function hasil_di_luar_kosakata_ditolak(): void
    {
        $unit = $this->ambilUnit();

        $this->expectException(BloodException::class);
        $this->expectExceptionMessageMatches("/'agak positif' tidak dikenali/");

        $this->skrining->screen($unit, $this->bersih(['hbsag' => 'agak positif']), $this->petugas);
    }

    #[Test]
    public function basis_data_menolak_hasil_di_luar_kosakata(): void
    {
        $unit = $this->ambilUnit();
        $skrining = $this->skrining->screen($unit, $this->bersih(), $this->petugas);

        $this->expectException(QueryException::class);

        UnitScreening::query()->whereKey($skrining->id)->update(['anti_hiv' => 'entah']);
    }

    #[Test]
    public function unit_yang_sudah_dikeluarkan_tidak_bisa_diskrining_lagi(): void
    {
        $unit = $this->unitTersedia();
        $this->transfusi->issue($unit->refresh(), 'Pasien Uji', $this->petugas);

        $this->expectException(BloodException::class);
        $this->expectExceptionMessageMatches('/hanya dicatat pada unit yang masih/');

        $this->skrining->screen($unit->refresh(), $this->bersih(), $this->petugas);
    }

    // ============================================== reaktif & meragukan

    #[Test]
    public function satu_hasil_reaktif_menolak_kantongnya(): void
    {
        $unit = $this->ambilUnit();

        $this->skrining->screen($unit, $this->bersih(['hbsag' => UnitScreening::REAKTIF]), $this->petugas);

        $this->assertSame(BloodUnit::STATUS_DITOLAK, $unit->refresh()->status);
    }

    #[Test]
    public function unit_reaktif_tidak_bisa_dirilis_dengan_cara_apa_pun(): void
    {
        $unit = $this->ambilUnit();
        $this->skrining->screen($unit, $this->bersih(['anti_hiv' => UnitScreening::REAKTIF]), $this->petugas);

        $this->expectException(BloodException::class);

        $this->skrining->releaseAfterScreening($unit->refresh(), $this->petugas);
    }

    #[Test]
    public function hasil_meragukan_menahan_bukan_menolak(): void
    {
        $unit = $this->unitTersedia();

        // Menolak kantong yang cuma meragukan membuang darah yang mungkin
        // baik; merilisnya membahayakan pasien. Jalan tengahnya menahan.
        // Waktunya digeser karena satu unit tidak boleh punya dua hasil
        // skrining pada detik yang sama — itu tanda kiriman ganda.
        $this->skrining->screen(
            $unit->refresh(),
            $this->bersih(['syphilis' => UnitScreening::MERAGUKAN]),
            $this->petugas,
            ['screened_at' => now()->addHours(2)]
        );

        $this->assertSame(BloodUnit::STATUS_DITAHAN, $unit->refresh()->status);
    }

    #[Test]
    public function unit_meragukan_tidak_bisa_dirilis_sebelum_diperiksa_ulang(): void
    {
        $unit = $this->ambilUnit();
        $this->skrining->screen($unit, $this->bersih(['malaria' => UnitScreening::MERAGUKAN]), $this->petugas);

        $this->expectException(BloodException::class);
        $this->expectExceptionMessageMatches('/Periksa ulang lebih dulu/');

        $this->skrining->releaseAfterScreening($unit->refresh(), $this->petugas);
    }

    #[Test]
    public function pemeriksaan_ulang_yang_bersih_membuka_jalan_rilis(): void
    {
        $unit = $this->ambilUnit();

        $this->skrining->screen($unit, $this->bersih(['malaria' => UnitScreening::MERAGUKAN]), $this->petugas, [
            'screened_at' => now()->subHours(3),
        ]);

        $ulang = $this->skrining->screen($unit->refresh(), $this->bersih(), $this->petugas);

        // Yang lama tetap ada; yang dipakai yang terbaru.
        $this->assertTrue($ulang->is_repeat);
        $this->assertCount(2, UnitScreening::query()->where('blood_unit_id', $unit->id)->get());

        $dirilis = $this->skrining->releaseAfterScreening($unit->refresh(), $this->petugas);
        $this->assertSame(BloodUnit::STATUS_TERSEDIA, $dirilis->status);
    }

    #[Test]
    public function unit_yang_belum_diskrining_bisa_ditagih(): void
    {
        $belum = $this->ambilUnit();
        $sudah = $this->ambilUnit();
        $this->skrining->screen($sudah, $this->bersih(), $this->petugas);

        $daftar = $this->skrining->awaitingScreening()->pluck('id')->all();

        $this->assertContains($belum->id, $daftar);
        $this->assertNotContains($sudah->id, $daftar);
    }

    // ============================================== look-back

    #[Test]
    public function donor_reaktif_langsung_dicekal_dan_memicu_penelusuran(): void
    {
        $donor = $this->daftarkanPendonor();
        $unit = $this->ambilUnit(['donor_id' => $donor->id]);

        $this->skrining->screen($unit, $this->bersih(['anti_hcv' => UnitScreening::REAKTIF]), $this->petugas);

        // Menolak satu kantong saja membiarkan kantong sebelumnya dari
        // donor yang sama tetap beredar.
        $this->assertTrue($donor->refresh()->isBlocked());
        $this->assertNull($donor->blocked_until, 'Cekal karena IMLTD reaktif seharusnya permanen.');

        $penelusuran = LookbackInvestigation::query()->where('donor_id', $donor->id)->first();
        $this->assertNotNull($penelusuran);
        $this->assertSame('skrining-reaktif', $penelusuran->trigger);
    }

    #[Test]
    public function penelusuran_menemukan_kantong_lama_dari_donor_yang_sama(): void
    {
        $donor = $this->daftarkanPendonor();

        $lama = $this->ambilUnit(['donor_id' => $donor->id]);
        $this->skrining->screen($lama, $this->bersih(), $this->petugas);
        $this->skrining->releaseAfterScreening($lama->refresh(), $this->petugas);

        $baru = $this->ambilUnit(['donor_id' => $donor->id]);
        $this->skrining->screen($baru, $this->bersih(['anti_hiv' => UnitScreening::REAKTIF]), $this->petugas);

        $penelusuran = LookbackInvestigation::query()->where('donor_id', $donor->id)->firstOrFail();

        $this->assertSame(2, $penelusuran->units_found);
        $this->assertContains($lama->unit_number, $penelusuran->unit_numbers);
    }

    #[Test]
    public function penelusuran_menyusuri_komponen_hasil_pemisahan(): void
    {
        $donor = $this->daftarkanPendonor();
        $whole = $this->unitTersedia(['donor_id' => $donor->id]);

        $komponen = $this->units->separate($whole->refresh(), [
            ['component' => 'prc', 'volume_ml' => 200, 'expiry_date' => now()->addDays(35)->toDateString()],
            ['component' => 'plasma', 'volume_ml' => 150, 'expiry_date' => now()->addDays(365)->toDateString()],
        ], $this->petugas);

        $ditemukan = $this->skrining->traceUnitsFrom($donor->refresh());

        // Induk plus dua anaknya: tautan datanya sudah ada sejak awal,
        // yang belum ada operasinya.
        $this->assertCount(3, $ditemukan);
        foreach ($komponen as $anak) {
            $this->assertTrue($ditemukan->contains('id', $anak->id));
        }
    }

    #[Test]
    public function pasien_yang_sudah_menerima_darah_ikut_disebutkan(): void
    {
        $donor = $this->daftarkanPendonor();

        $terlanjur = $this->unitTersedia(['donor_id' => $donor->id]);
        $this->transfusi->issue($terlanjur->refresh(), 'Ny. Sudah Ditransfusi', $this->petugas, 4242);

        $penelusuran = $this->skrining->openLookback(
            $donor->refresh(), 'laporan-donor', 'Donor melaporkan hasil tes HIV reaktif.', $this->petugas
        );

        $this->assertSame(1, $penelusuran->units_already_issued);
        $this->assertTrue($penelusuran->hasAffectedPatients());
        $this->assertSame('Ny. Sudah Ditransfusi', $penelusuran->affected_patients[0]['patient_name']);
    }

    #[Test]
    public function penarikan_hanya_menyentuh_kantong_yang_masih_ada(): void
    {
        $donor = $this->daftarkanPendonor();

        $masihAda = $this->unitTersedia(['donor_id' => $donor->id]);
        $terlanjur = $this->unitTersedia(['donor_id' => $donor->id]);
        $this->transfusi->issue($terlanjur->refresh(), 'Tn. Penerima', $this->petugas);

        $penelusuran = $this->skrining->openLookback(
            $donor->refresh(), 'laporan-donor', 'Donor melaporkan gejala.', $this->petugas
        );
        $ditarik = $this->skrining->recallUnits($penelusuran, $this->petugas);

        $this->assertSame(1, $ditarik->units_recalled);
        $this->assertSame(BloodUnit::STATUS_DITOLAK, $masihAda->refresh()->status);
        $this->assertSame(BloodUnit::STATUS_DIKELUARKAN, $terlanjur->refresh()->status);
        $this->assertSame(0, $ditarik->pendingRecall());
    }

    #[Test]
    public function penelusuran_kedua_atas_donor_yang_sama_melanjutkan_yang_berjalan(): void
    {
        $donor = $this->daftarkanPendonor();

        $pertama = $this->skrining->openLookback($donor, 'temuan-lain', 'Temuan awal.', $this->petugas);
        $kedua = $this->skrining->openLookback($donor, 'laporan-donor', 'Laporan menyusul.', $this->petugas);

        $this->assertSame($pertama->id, $kedua->id);
    }

    #[Test]
    public function penelusuran_ditutup_dengan_kesimpulan(): void
    {
        $donor = $this->daftarkanPendonor();
        $penelusuran = $this->skrining->openLookback($donor, 'temuan-lain', 'Temuan awal.', $this->petugas);

        $selesai = $this->skrining->closeLookback(
            $penelusuran,
            'Seluruh kantong ditarik; tidak ada pasien yang terlanjur menerima.'
        );

        $this->assertSame(LookbackInvestigation::SELESAI, $selesai->status);
        $this->assertNotNull($selesai->closed_at);
    }

    #[Test]
    public function penelusuran_tanpa_kesimpulan_tidak_bisa_ditutup(): void
    {
        $donor = $this->daftarkanPendonor();
        $penelusuran = $this->skrining->openLookback($donor, 'temuan-lain', 'Temuan awal.', $this->petugas);

        $this->expectException(BloodException::class);
        $this->expectExceptionMessageMatches('/dibuktikan pernah dituntaskan/');

        $this->skrining->closeLookback($penelusuran, '   ');
    }

    #[Test]
    public function basis_data_menolak_penelusuran_selesai_tanpa_kesimpulan(): void
    {
        $donor = $this->daftarkanPendonor();
        $penelusuran = $this->skrining->openLookback($donor, 'temuan-lain', 'Temuan awal.', $this->petugas);

        $this->expectException(QueryException::class);

        LookbackInvestigation::query()->whereKey($penelusuran->id)->update([
            'status' => LookbackInvestigation::SELESAI, 'closed_at' => now(),
        ]);
    }

    #[Test]
    public function penelusuran_tanpa_uraian_pemicu_ditolak(): void
    {
        $donor = $this->daftarkanPendonor();

        $this->expectException(BloodException::class);
        $this->expectExceptionMessageMatches('/dibaca kembali bertahun kemudian/');

        $this->skrining->openLookback($donor, 'temuan-lain', '  ', $this->petugas);
    }

    #[Test]
    public function basis_data_menolak_hitungan_yang_mustahil(): void
    {
        $donor = $this->daftarkanPendonor();
        $penelusuran = $this->skrining->openLookback($donor, 'temuan-lain', 'Temuan awal.', $this->petugas);

        $this->expectException(QueryException::class);

        // Yang ditarik tidak mungkin lebih banyak daripada yang ditemukan.
        LookbackInvestigation::query()->whereKey($penelusuran->id)
            ->update(['units_found' => 1, 'units_recalled' => 5]);
    }

    // ---------------------------------------------------------------- fixture

    /**
     * @param  array<string, string>  $override
     * @return array<string, string>
     */
    private function bersih(array $override = []): array
    {
        return $override + [
            'hbsag' => UnitScreening::NON_REAKTIF,
            'anti_hcv' => UnitScreening::NON_REAKTIF,
            'anti_hiv' => UnitScreening::NON_REAKTIF,
            'syphilis' => UnitScreening::NON_REAKTIF,
            'malaria' => UnitScreening::NON_REAKTIF,
        ];
    }

    private function unitTersedia(array $override = []): BloodUnit
    {
        $unit = $this->ambilUnit($override);
        $this->skrining->screen($unit, $this->bersih(), $this->petugas);

        return $this->skrining->releaseAfterScreening($unit->refresh(), $this->petugas);
    }

    private function ambilUnit(array $override = []): BloodUnit
    {
        return $this->units->collect(array_merge([
            'donor_id' => $this->daftarkanPendonor()->id,
            'blood_type' => 'O',
            'rhesus' => '+',
            'component' => 'whole-blood',
            'volume_ml' => 350,
            'collected_at' => now(),
            'expiry_date' => now()->addDays(35)->toDateString(),
        ], $override));
    }

    private function daftarkanPendonor(): Donor
    {
        static $urut = 0;
        $urut++;

        return $this->donors->register([
            'name' => 'Pendonor Skrining '.$urut,
            'blood_type' => 'O', 'rhesus' => '+', 'sex' => 'L', 'birth_date' => '1990-06-06',
        ]);
    }
}
