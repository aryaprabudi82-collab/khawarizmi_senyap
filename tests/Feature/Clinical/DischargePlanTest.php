<?php

namespace Tests\Feature\Clinical;

use App\Modules\Catalog\Models\Payer;
use App\Modules\Clinical\Models\DischargePlan;
use App\Modules\Clinical\Services\ClinicalException;
use App\Modules\Clinical\Services\DischargePlanService;
use App\Modules\Encounter\Models\Registration;
use App\Modules\Encounter\Services\RegistrationService;
use App\Modules\Identity\Services\PatientRegistry;
use App\Modules\Inpatient\Models\Admission;
use App\Modules\Inpatient\Models\Room;
use App\Modules\Inpatient\Services\AdmissionService;
use App\Modules\Inpatient\Services\RoomService;
use App\Modules\Organization\Models\Practitioner;
use App\Modules\Organization\Models\Unit;
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
 * Perencanaan pemulangan (domain M item H).
 *
 * Yang paling perlu dikunci:
 *
 * 1. BANTUAN YANG DIBUTUHKAN ADALAH DAFTAR. Enum MySQL Khanza hanya
 *    memuat satu pilihan; pasien stroke yang butuh bantuan mandi,
 *    berpakaian, sekaligus minum obat harus memilih salah satunya.
 * 2. KOSONG BERARTI BELUM DITANYAKAN, BUKAN "TIDAK". false adalah
 *    jawaban yang sah dan justru yang paling sering benar.
 * 3. RENCANA DISUSUN SEJAK AWAL, dan yang telat bisa disebutkan.
 */
class DischargePlanTest extends TestCase
{
    use RefreshDatabase;

    private DischargePlanService $rencana;

    private AdmissionService $admissions;

    private RoomService $rooms;

    private User $perawat;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([PermissionCatalogSeeder::class, RoleSeeder::class, ReferenceDataSeeder::class]);

        $this->rencana = app(DischargePlanService::class);
        $this->admissions = app(AdmissionService::class);
        $this->rooms = app(RoomService::class);

        $this->perawat = User::query()->create([
            'username' => 'uji-rencana-pulang', 'name' => 'Perawat Ruangan',
            'password' => 'password', 'is_active' => true,
        ]);
        $this->perawat->roles()->attach(Role::query()->where('code', 'petugas-ranap')->firstOrFail());
    }

    // ------------------------------------------------------ bantuan itu daftar

    #[Test]
    public function bantuan_yang_dibutuhkan_boleh_lebih_dari_satu(): void
    {
        $rencana = $this->rencana->open($this->rawatInap()->id, $this->perawat);

        // Persis kasus yang tidak muat di enum Khanza.
        $terisi = $this->rencana->save($rencana, [
            'assistance_needed' => ['mandi', 'berpakaian', 'minum-obat'],
        ]);

        $this->assertSame(['mandi', 'berpakaian', 'minum-obat'], $terisi->assistance_needed);
    }

    #[Test]
    public function jenis_bantuan_di_luar_kosakata_ditolak(): void
    {
        $rencana = $this->rencana->open($this->rawatInap()->id, $this->perawat);

        $this->expectException(ClinicalException::class);
        $this->expectExceptionMessageMatches('/tidak dikenali: dipijat/');

        $this->rencana->save($rencana, ['assistance_needed' => ['mandi', 'dipijat']]);
    }

    #[Test]
    public function bantuan_yang_sama_tidak_tercatat_dua_kali(): void
    {
        $rencana = $this->rencana->open($this->rawatInap()->id, $this->perawat);

        $terisi = $this->rencana->save($rencana, [
            'assistance_needed' => ['mandi', 'mandi', 'diet'],
        ]);

        $this->assertSame(['mandi', 'diet'], $terisi->assistance_needed);
    }

    #[Test]
    public function tidak_butuh_bantuan_apa_pun_adalah_jawaban_yang_sah(): void
    {
        $rencana = $this->rencana->open($this->rawatInap()->id, $this->perawat);

        $terisi = $this->rencana->save($rencana, ['assistance_needed' => []]);

        $this->assertSame([], $terisi->assistance_needed);
    }

    // -------------------------------------------- kosong bukan berarti "tidak"

    #[Test]
    public function pertanyaan_pengaruh_kosong_saat_rencana_baru_dibuka(): void
    {
        $rencana = $this->rencana->open($this->rawatInap()->id, $this->perawat);

        // Bukan false — belum ada yang menanyakannya kepada pasien.
        $this->assertNull($rencana->affects_family);
        $this->assertNull($rencana->affects_work_or_school);
        $this->assertNull($rencana->affects_finance);
        $this->assertNull($rencana->anticipated_problems);
    }

    #[Test]
    public function rencana_tidak_bisa_ditutup_selama_ada_pertanyaan_belum_dijawab(): void
    {
        $rencana = $this->rencana->open($this->rawatInap()->id, $this->perawat);

        $this->rencana->save($rencana, [
            'caregiver_name' => 'Siti Aminah',
            'caregiver_relation' => 'Anak',
            'affects_family' => true,
            'affects_work_or_school' => false,
            // affects_finance dan anticipated_problems sengaja dilewati.
        ]);

        $this->expectException(ClinicalException::class);
        $this->expectExceptionMessageMatches('/pengaruh terhadap keuangan/');

        $this->rencana->finalize($rencana->refresh(), $this->perawat);
    }

    #[Test]
    public function jawaban_tidak_untuk_semua_pertanyaan_sudah_cukup_untuk_menutup(): void
    {
        $rencana = $this->rencana->open($this->rawatInap()->id, $this->perawat);

        // Empat-empatnya false. Kalau kode memakai empty() alih-alih
        // membandingkan dengan null, rencana ini akan ditolak seolah
        // belum dijawab sama sekali.
        $this->rencana->save($rencana, [
            'caregiver_name' => 'Budi Santoso',
            'caregiver_relation' => 'Suami',
            'affects_family' => false,
            'affects_work_or_school' => false,
            'affects_finance' => false,
            'anticipated_problems' => false,
        ]);

        $final = $this->rencana->finalize($rencana->refresh(), $this->perawat);

        $this->assertSame(DischargePlan::FINAL, $final->status);
        $this->assertNotNull($final->finalized_at);
    }

    #[Test]
    public function rencana_tanpa_nama_keluarga_tidak_bisa_ditutup(): void
    {
        $rencana = $this->rencana->open($this->rawatInap()->id, $this->perawat);

        $this->rencana->save($rencana, [
            'affects_family' => false, 'affects_work_or_school' => false,
            'affects_finance' => false, 'anticipated_problems' => false,
        ]);

        $this->expectException(ClinicalException::class);
        $this->expectExceptionMessageMatches('/belum diserahkan kepada siapa pun/');

        $this->rencana->finalize($rencana->refresh(), $this->perawat);
    }

    // ------------------------------------------------------------- tenggat

    #[Test]
    public function rencana_yang_dibuat_hari_pertama_tidak_terlambat(): void
    {
        $rencana = $this->rencana->open($this->rawatInap()->id, $this->perawat);

        $this->assertFalse($rencana->isLate());
        $this->assertLessThan(1, $rencana->hoursAfterAdmission());
    }

    #[Test]
    public function keterlambatan_dihitung_dari_waktu_masuk_bukan_disimpan(): void
    {
        $admisi = $this->rawatInap();
        $rencana = $this->rencana->open($admisi->id, $this->perawat);

        // Admisi digeser mundur tiga hari; keterlambatan ikut berubah
        // karena dihitung, bukan disimpan sebagai angka tersendiri.
        $rencana->update(['admitted_at' => now()->subDays(3)]);

        $this->assertTrue($rencana->refresh()->isLate());
        $this->assertGreaterThan(70, $rencana->hoursAfterAdmission());
    }

    #[Test]
    public function admisi_lewat_tenggat_tanpa_rencana_bisa_disebutkan(): void
    {
        $admisi = $this->rawatInap('K020', 'B01');
        Admission::query()->whereKey($admisi->id)->update(['admitted_at' => now()->subDays(4)]);

        $this->assertContains(
            $admisi->id,
            $this->rencana->overdue()->pluck('admission_id')->all()
        );

        $this->rencana->open($admisi->id, $this->perawat);

        $this->assertNotContains(
            $admisi->id,
            $this->rencana->overdue()->pluck('admission_id')->all(),
            'Admisi yang sudah punya rencana tidak boleh ikut terdaftar lagi.'
        );
    }

    #[Test]
    public function pasien_yang_sudah_pulang_tidak_ikut_daftar_tenggat(): void
    {
        $admisi = $this->rawatInap('K021', 'B01');
        Admission::query()->whereKey($admisi->id)->update(['admitted_at' => now()->subDays(4)]);
        $this->admissions->discharge($admisi->refresh(), 'sembuh', null, $this->perawat->id);

        $this->assertNotContains(
            $admisi->id,
            $this->rencana->overdue()->pluck('admission_id')->all()
        );
    }

    // ------------------------------------------------------ satu admisi satu

    #[Test]
    public function membuka_dua_kali_melanjutkan_rencana_yang_sama(): void
    {
        $admisi = $this->rawatInap();

        $pertama = $this->rencana->open($admisi->id, $this->perawat);
        $kedua = $this->rencana->open($admisi->id, $this->perawat);

        $this->assertSame($pertama->id, $kedua->id);
    }

    #[Test]
    public function rencana_final_tidak_bisa_diubah(): void
    {
        $admisi = $this->rawatInap();
        $rencana = $this->rencana->open($admisi->id, $this->perawat);

        $this->rencana->save($rencana, [
            'caregiver_name' => 'Rina', 'affects_family' => false,
            'affects_work_or_school' => false, 'affects_finance' => false,
            'anticipated_problems' => false,
        ]);
        $final = $this->rencana->finalize($rencana->refresh(), $this->perawat);

        $this->expectException(ClinicalException::class);
        $this->expectExceptionMessageMatches('/sudah diberi tahu isi yang lama/');

        $this->rencana->save($final, ['education_given' => 'Diubah diam-diam']);
    }

    #[Test]
    public function rencana_yang_dibatalkan_boleh_diganti_yang_baru(): void
    {
        $admisi = $this->rawatInap();
        $lama = $this->rencana->open($admisi->id, $this->perawat);

        $this->rencana->cancel($lama, 'Pasien pindah ke ruang isolasi, rencananya berubah');

        $baru = $this->rencana->open($admisi->id, $this->perawat);

        $this->assertNotSame($lama->id, $baru->id);
        $this->assertStringContainsString('Dibatalkan:', $lama->fresh()->education_given);
    }

    #[Test]
    public function admisi_tidak_dikenal_ditolak(): void
    {
        $this->expectException(ClinicalException::class);
        $this->expectExceptionMessageMatches('/Admisi tidak ditemukan/');

        $this->rencana->open(999999, $this->perawat);
    }

    // ------------------------------------------------------------- basis data

    #[Test]
    public function basis_data_menolak_dua_rencana_aktif_untuk_satu_admisi(): void
    {
        $admisi = $this->rawatInap();
        $this->rencana->open($admisi->id, $this->perawat);

        $this->expectException(QueryException::class);

        DischargePlan::query()->create([
            'admission_id' => $admisi->id,
            'registration_id' => $admisi->registration_id,
            'patient_id' => $admisi->patient_id,
            'admission_number' => 'DUP', 'patient_mrn' => 'X', 'patient_name' => 'Duplikat',
            'admitted_at' => now(), 'assistance_needed' => [],
            'status' => DischargePlan::DRAF,
        ]);
    }

    // ---------------------------------------------------------------- fixture

    private function rawatInap(string $kamar = 'K001', string $bed = 'B01'): Admission
    {
        $room = Room::query()->where('room_number', $kamar)->first()
            ?? $this->rooms->createRoom(['room_number' => $kamar, 'room_class' => 'kelas-3']);

        $tempat = $this->rooms->addBed($room, $bed);

        return $this->admissions->admit($this->daftarkanRanap()->id, $tempat, $this->perawat->id);
    }

    private function daftarkanRanap(): Registration
    {
        static $urut = 0;
        $urut++;

        $pasien = app(PatientRegistry::class)->register([
            'name' => 'Pasien Rencana Pulang '.$urut, 'sex' => 'P', 'birth_date' => '1975-05-05',
        ]);

        return app(RegistrationService::class)->register(
            patientId: $pasien->id,
            unitId: Unit::query()->where('code', 'POL-UMUM')->value('id'),
            payerId: Payer::query()->where('code', 'UMUM')->value('id'),
            practitionerId: Practitioner::query()->where('is_active', true)->value('id'),
            extra: ['care_type' => 'ranap'],
        );
    }
}
