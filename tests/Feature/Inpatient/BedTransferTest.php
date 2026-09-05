<?php

namespace Tests\Feature\Inpatient;

use App\Modules\Billing\Services\InvoiceService;
use App\Modules\Catalog\Models\Payer;
use App\Modules\Encounter\Models\Registration;
use App\Modules\Encounter\Services\RegistrationService;
use App\Modules\Identity\Services\PatientRegistry;
use App\Modules\Inpatient\Models\Admission;
use App\Modules\Inpatient\Models\Bed;
use App\Modules\Inpatient\Models\Room;
use App\Modules\Inpatient\Services\AdmissionService;
use App\Modules\Inpatient\Services\InpatientException;
use App\Modules\Organization\Models\Unit;
use App\Modules\Platform\Database\Seeders\PermissionCatalogSeeder;
use App\Modules\Platform\Database\Seeders\RoleSeeder;
use App\Modules\Platform\Models\Role;
use App\Modules\Platform\Models\User;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Memperbaiki batasan yang diakui saat domain I item A dibangun: sebelum
 * ada riwayat penempatan bed, seluruh hari menginap dihitung dengan tarif
 * kamar TERKINI — jadi pasien yang pindah kelas di tengah rawat tertagih
 * surut dengan tarif kamar barunya. Itu salah tagih, bukan sekadar
 * laporan kurang rapi.
 */
class BedTransferTest extends TestCase
{
    use RefreshDatabase;

    private AdmissionService $admissions;
    private InvoiceService $invoices;
    private User $petugasRanap;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([PermissionCatalogSeeder::class, RoleSeeder::class, ReferenceDataSeeder::class]);

        $this->admissions = app(AdmissionService::class);
        $this->invoices = app(InvoiceService::class);

        $this->petugasRanap = User::query()->create([
            'username' => 'uji-ranap-pindah', 'name' => 'Petugas Ranap Uji', 'password' => 'password', 'is_active' => true,
        ]);
        $this->petugasRanap->roles()->attach(Role::query()->where('code', 'petugas-ranap')->firstOrFail());
    }

    #[Test]
    public function admisi_langsung_membuka_riwayat_penempatan(): void
    {
        $bed = $this->bed('K3-A', 'kelas-3', 250_000);
        $admisi = $this->admissions->admit($this->daftarkan()->id, $bed);

        $this->assertCount(1, $admisi->bedAssignments);
        $this->assertTrue($admisi->bedAssignments->first()->isOpen());
        $this->assertSame($bed->id, $admisi->bedAssignments->first()->bed_id);
    }

    #[Test]
    public function pindah_bed_menutup_penempatan_lama_dan_membuka_yang_baru(): void
    {
        $lama = $this->bed('K3-A', 'kelas-3', 250_000);
        $baru = $this->bed('VIP-A', 'vip', 1_000_000);

        $admisi = $this->admissions->admit($this->daftarkan()->id, $lama);
        $this->admissions->transferBed($admisi, $baru, 'Naik kelas atas permintaan keluarga', $this->petugasRanap->id);

        $riwayat = $admisi->refresh()->bedAssignments;

        $this->assertCount(2, $riwayat);
        $this->assertTrue($riwayat->first()->isOpen(), 'Yang terbaru masih terbuka');
        $this->assertSame($baru->id, $riwayat->first()->bed_id);
        $this->assertFalse($riwayat->last()->isOpen(), 'Yang lama sudah ditutup');
        $this->assertSame($baru->id, $admisi->bed_id, 'bed_id tetap jadi cache bed terkini');
    }

    #[Test]
    public function bed_lama_dilepas_dan_bed_baru_terisi(): void
    {
        $lama = $this->bed('K3-A', 'kelas-3', 250_000);
        $baru = $this->bed('VIP-A', 'vip', 1_000_000);

        $admisi = $this->admissions->admit($this->daftarkan()->id, $lama);
        $this->admissions->transferBed($admisi, $baru);

        $this->assertSame(Bed::STATUS_DIBERSIHKAN, $lama->refresh()->status);
        $this->assertSame(Bed::STATUS_TERISI, $baru->refresh()->status);
    }

    /**
     * Inti perbaikan ini. Tanpa riwayat penempatan, ketiga hari di kelas 3
     * akan ikut ditagih tarif VIP begitu pasien pindah.
     */
    #[Test]
    public function hari_sebelum_pindah_tetap_ditagih_tarif_kamar_lama(): void
    {
        $kelas3 = $this->bed('K3-A', 'kelas-3', 250_000);
        $vip = $this->bed('VIP-A', 'vip', 1_000_000);
        $registrasi = $this->daftarkan();

        // Masuk 4 hari lalu di kelas 3.
        $this->travelTo(now()->subDays(4)->setTime(8, 0));
        $admisi = $this->admissions->admit($registrasi->id, $kelas3);

        // Pindah VIP kemarin.
        $this->travelTo(now()->addDays(3)->setTime(10, 0));
        $this->admissions->transferBed($admisi, $vip, 'Naik kelas');

        $this->travelBack();
        $tagihan = $this->invoices->openInvoice($registrasi->id);
        $barisKamar = $tagihan->chargeLines()->where('source_type', 'kamar')->orderBy('charged_at')->get();

        $this->assertCount(5, $barisKamar, 'Masuk 4 hari lalu, masih dirawat: 5 hari');

        $kelas3Ditagih = $barisKamar->where('unit_price', '250000.00')->count();
        $vipDitagih = $barisKamar->where('unit_price', '1000000.00')->count();

        $this->assertSame(3, $kelas3Ditagih, 'Tiga hari pertama tetap tarif kelas 3');
        $this->assertSame(2, $vipDitagih, 'Sisanya tarif VIP');
        $this->assertSame(
            3 * 250_000.0 + 2 * 1_000_000.0,
            (float) $barisKamar->sum('amount'),
            'Total tidak boleh ikut naik surut'
        );
    }

    #[Test]
    public function uraian_baris_kamar_menyebut_kamar_yang_sungguh_ditempati(): void
    {
        $kelas3 = $this->bed('K3-A', 'kelas-3', 250_000);
        $vip = $this->bed('VIP-A', 'vip', 1_000_000);
        $registrasi = $this->daftarkan();

        $this->travelTo(now()->subDays(2)->setTime(8, 0));
        $admisi = $this->admissions->admit($registrasi->id, $kelas3);

        $this->travelTo(now()->addDays(2)->setTime(9, 0));
        $this->admissions->transferBed($admisi, $vip);

        $this->travelBack();
        $uraian = $this->invoices->openInvoice($registrasi->id)
            ->chargeLines()->where('source_type', 'kamar')->pluck('description');

        $this->assertTrue($uraian->contains(fn ($u) => str_contains($u, 'K3-A')), 'Ada hari di kamar lama');
        $this->assertTrue($uraian->contains(fn ($u) => str_contains($u, 'VIP-A')), 'Ada hari di kamar baru');
    }

    #[Test]
    public function pulang_menutup_penempatan_yang_masih_terbuka(): void
    {
        $bed = $this->bed('K3-A', 'kelas-3', 250_000);
        $admisi = $this->admissions->admit($this->daftarkan()->id, $bed);

        $this->admissions->discharge($admisi, 'sembuh', null);

        $this->assertFalse($admisi->refresh()->bedAssignments->first()->isOpen());
        $this->assertNotNull($admisi->bedAssignments->first()->released_at);
    }

    #[Test]
    public function pindah_ke_bed_yang_sama_ditolak(): void
    {
        $bed = $this->bed('K3-A', 'kelas-3', 250_000);
        $admisi = $this->admissions->admit($this->daftarkan()->id, $bed);

        $this->expectException(InpatientException::class);
        $this->expectExceptionMessage('sudah menempati');

        $this->admissions->transferBed($admisi, $bed);
    }

    #[Test]
    public function pindah_ke_bed_yang_sedang_terisi_ditolak(): void
    {
        $bedA = $this->bed('K3-A', 'kelas-3', 250_000);
        $bedB = $this->bed('K3-B', 'kelas-3', 250_000);

        $admisiA = $this->admissions->admit($this->daftarkan()->id, $bedA);
        $this->admissions->admit($this->daftarkan()->id, $bedB);

        $this->expectException(InpatientException::class);
        $this->expectExceptionMessage('tidak tersedia');

        $this->admissions->transferBed($admisiA, $bedB->refresh());
    }

    #[Test]
    public function pasien_yang_sudah_pulang_tidak_bisa_dipindah(): void
    {
        $bed = $this->bed('K3-A', 'kelas-3', 250_000);
        $lain = $this->bed('VIP-A', 'vip', 1_000_000);

        $admisi = $this->admissions->admit($this->daftarkan()->id, $bed);
        $this->admissions->discharge($admisi, 'sembuh', null);

        $this->expectException(InpatientException::class);
        $this->expectExceptionMessage('sudah tidak dirawat');

        $this->admissions->transferBed($admisi->refresh(), $lain);
    }

    #[Test]
    public function satu_admisi_hanya_boleh_punya_satu_penempatan_terbuka(): void
    {
        $bed = $this->bed('K3-A', 'kelas-3', 250_000);
        $admisi = $this->admissions->admit($this->daftarkan()->id, $bed);

        $this->expectException(\Illuminate\Database\QueryException::class);

        // Menambah penempatan terbuka kedua harus ditolak basis data,
        // bukan hanya oleh kedisiplinan kode pemanggilnya.
        $admisi->bedAssignments()->create([
            'bed_id' => $bed->id,
            'assigned_at' => now(),
        ]);
    }

    #[Test]
    public function pindah_bed_bisa_lewat_http(): void
    {
        $lama = $this->bed('K3-A', 'kelas-3', 250_000);
        $baru = $this->bed('VIP-A', 'vip', 1_000_000);
        $admisi = $this->admissions->admit($this->daftarkan()->id, $lama);

        $this->actingAs($this->petugasRanap)
            ->post(route('inpatient.admisi.pindah-bed', $admisi), [
                'bed_id' => $baru->id,
                'reason' => 'Naik kelas',
            ])
            ->assertRedirect()
            ->assertSessionHas('sukses');

        $this->assertSame($baru->id, $admisi->refresh()->bed_id);
        $this->assertCount(2, $admisi->bedAssignments);
    }

    // ------------------------------------------------------------------ bantu

    private function bed(string $nomor, string $kelas, float $tarif): Bed
    {
        $kamar = Room::query()->create([
            'room_number' => $nomor,
            'room_class' => $kelas,
            'daily_rate' => $tarif,
            'is_active' => true,
        ]);

        return Bed::query()->create([
            'room_id' => $kamar->id,
            'bed_number' => 'A',
            'status' => Bed::STATUS_TERSEDIA,
        ]);
    }

    private function daftarkan(): Registration
    {
        static $urut = 0;
        $urut++;

        $pasien = app(PatientRegistry::class)->register([
            'name' => 'Pasien Pindah ' . $urut, 'sex' => 'L', 'birth_date' => '1990-01-01',
        ]);

        return app(RegistrationService::class)->register(
            patientId: $pasien->id,
            unitId: Unit::query()->where('code', 'POL-UMUM')->firstOrFail()->id,
            payerId: Payer::query()->where('code', 'UMUM')->value('id'),
            extra: ['care_type' => 'ranap'],
        );
    }
}
