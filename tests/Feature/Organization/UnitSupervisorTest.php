<?php

namespace Tests\Feature\Organization;

use App\Modules\Organization\Models\OperatingRoom;
use App\Modules\Organization\Models\Practitioner;
use App\Modules\Organization\Models\Unit;
use App\Modules\Organization\Models\UnitSupervisor;
use App\Modules\Organization\Services\OrganizationDirectory;
use App\Modules\Organization\Services\OrganizationException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Penanggung jawab unit penunjang & master ruang operasi (domain U item B).
 *
 * Yang dikunci:
 *
 * 1. PENUGASAN BERJANGKA WAKTU — "siapa PJ lab bulan Maret" punya jawaban,
 *    pertanyaan yang `set_pjlab` Khanza tidak bisa jawab sama sekali.
 * 2. SATU PJ PER UNIT pada satu waktu, ditegakkan indeks unik parsial.
 * 3. PENUGASAN LAMA TIDAK HILANG saat berganti.
 * 4. RUANG OPERASI DIPILIH DARI MASTER — laporan RL tidak lagi memecah satu
 *    ruang jadi dua baris karena dua ejaan.
 */
class UnitSupervisorTest extends TestCase
{
    use RefreshDatabase;

    private OrganizationDirectory $directory;

    protected function setUp(): void
    {
        parent::setUp();

        $this->directory = app(OrganizationDirectory::class);
    }

    #[Test]
    public function penanggung_jawab_bisa_ditanyakan_menurut_tanggal(): void
    {
        $lab = $this->unit('LAB', 'Laboratorium');
        $andi = $this->praktisi('DR-ANDI', 'dr. Andi');
        $budi = $this->praktisi('DR-BUDI', 'dr. Budi');

        $this->directory->assignSupervisor($lab, $andi, '2026-01-01', 'SK-01/2026');
        $this->directory->assignSupervisor($lab, $budi, '2026-07-01', 'SK-14/2026');

        /*
         * Inti seluruh tabel ini. `set_pjlab` Khanza cuma menyimpan siapa
         * yang menjabat HARI INI, jadi hasil pemeriksaan Maret yang dicetak
         * ulang hari ini akan menyebut penanggung jawab yang belum menjabat
         * saat pemeriksaan itu dikerjakan.
         */
        $this->assertSame('dr. Andi', $this->directory->supervisorOn($lab->id, '2026-03-15')->practitioner->name);
        $this->assertSame('dr. Budi', $this->directory->supervisorOn($lab->id, '2026-08-15')->practitioner->name);

        // Tepat pada hari mulai, yang baru sudah menjabat.
        $this->assertSame('dr. Budi', $this->directory->supervisorOn($lab->id, '2026-07-01')->practitioner->name);

        // Sehari sebelumnya masih yang lama — penutupan otomatisnya jatuh
        // pada hari sebelum yang baru mulai, bukan pada hari yang sama.
        $this->assertSame('dr. Andi', $this->directory->supervisorOn($lab->id, '2026-06-30')->practitioner->name);

        // Sebelum ada penugasan sama sekali: null, bukan yang pertama.
        $this->assertNull($this->directory->supervisorOn($lab->id, '2025-12-31'));
    }

    #[Test]
    public function penugasan_lama_tidak_hilang_saat_berganti(): void
    {
        $lab = $this->unit('LAB', 'Laboratorium');
        $andi = $this->praktisi('DR-ANDI', 'dr. Andi');
        $budi = $this->praktisi('DR-BUDI', 'dr. Budi');

        $this->directory->assignSupervisor($lab, $andi, '2026-01-01');
        $this->directory->assignSupervisor($lab, $budi, '2026-07-01');

        // Khanza menimpa satu baris; di sini keduanya tinggal.
        $this->assertSame(2, UnitSupervisor::query()->where('unit_id', $lab->id)->count());

        $lama = UnitSupervisor::query()->where('practitioner_id', $andi->id)->firstOrFail();

        $this->assertSame('2026-06-30', $lama->end_date->toDateString());
        $this->assertFalse($lama->masihMenjabat());
    }

    #[Test]
    public function hanya_satu_penanggung_jawab_menjabat_per_unit(): void
    {
        $lab = $this->unit('LAB', 'Laboratorium');
        $andi = $this->praktisi('DR-ANDI', 'dr. Andi');
        $budi = $this->praktisi('DR-BUDI', 'dr. Budi');

        $this->directory->assignSupervisor($lab, $andi, '2026-01-01');

        /*
         * Aturannya ditegakkan indeks unik parsial, bukan hanya service:
         * impor dan perbaikan data manual lewat di bawah kode aplikasi. Dua
         * penanggung jawab bersamaan berarti tidak ada yang tahu tanda
         * tangan siapa yang sah pada hasil pemeriksaan.
         */
        $this->expectException(QueryException::class);

        UnitSupervisor::query()->create([
            'unit_id' => $lab->id,
            'practitioner_id' => $budi->id,
            'start_date' => '2026-02-01',
        ]);
    }

    #[Test]
    public function penanggung_jawab_baru_tidak_bisa_mulai_sebelum_yang_berjalan(): void
    {
        $lab = $this->unit('LAB', 'Laboratorium');
        $andi = $this->praktisi('DR-ANDI', 'dr. Andi');
        $budi = $this->praktisi('DR-BUDI', 'dr. Budi');

        $this->directory->assignSupervisor($lab, $andi, '2026-07-01');

        $this->expectException(OrganizationException::class);
        $this->expectExceptionMessage('sebelum penugasan yang sedang berjalan');

        // Kalau dibiarkan, penutupan otomatis akan menulis tanggal akhir
        // yang lebih awal daripada tanggal mulainya sendiri.
        $this->directory->assignSupervisor($lab, $budi, '2026-03-01');
    }

    #[Test]
    public function unit_penunjang_tanpa_penanggung_jawab_terdaftar(): void
    {
        $lab = $this->unit('LAB', 'Laboratorium');
        $rad = $this->unit('RAD', 'Radiologi');
        $this->unit('POLI', 'Poli Umum', 'poliklinik');

        $andi = $this->praktisi('DR-ANDI', 'dr. Andi');
        $this->directory->assignSupervisor($lab, $andi, '2026-01-01');

        $tanpa = $this->directory->unitsWithoutSupervisor();

        // Daftar kejujuran: unit penunjang tanpa PJ bukan keadaan yang sah
        // menurut akreditasi, dan lebih baik terlihat daripada baru
        // ketahuan saat diperiksa.
        $this->assertCount(1, $tanpa);
        $this->assertSame($rad->id, $tanpa->first()->id);

        // Poliklinik bukan unit penunjang, jadi tidak ikut terdaftar —
        // daftar yang mencantumkan hal yang tidak perlu akan diabaikan
        // seluruhnya, dan gunanya hilang.
        $this->assertFalse($tanpa->contains(fn ($u) => $u->code === 'POLI'));
    }

    // ------------------------------------------------------ ruang operasi

    #[Test]
    public function ruang_operasi_hanya_sah_kalau_ada_di_master_dan_aktif(): void
    {
        OperatingRoom::query()->create(['code' => 'OK1', 'name' => 'Kamar Operasi 1', 'is_active' => true]);
        OperatingRoom::query()->create(['code' => 'OK9', 'name' => 'Kamar Operasi 9', 'is_active' => false]);

        $this->assertTrue($this->directory->operatingRoomIsUsable('OK1'));

        // Ejaan bebas ditolak — inilah yang dulu memecah laporan RL 3.6:
        // "OK 1" dan "OK1" terhitung dua kamar operasi berbeda pada laporan
        // wajib, tanpa satu pun galat muncul.
        $this->assertFalse($this->directory->operatingRoomIsUsable('OK 1'));
        $this->assertFalse($this->directory->operatingRoomIsUsable('Kamar Operasi 1'));

        // Ruang yang sudah ditutup tidak bisa dipakai mencatat yang BARU.
        $this->assertFalse($this->directory->operatingRoomIsUsable('OK9'));

        // Kosong tetap sah: ruang operasi memang boleh belum ditentukan
        // saat jadwal pertama kali dibuat.
        $this->assertTrue($this->directory->operatingRoomIsUsable(null));
        $this->assertTrue($this->directory->operatingRoomIsUsable(''));
    }

    #[Test]
    public function master_ruang_operasi_lahir_kosong(): void
    {
        /*
         * Berapa kamar operasi RSP UI dan bagaimana penomorannya adalah
         * kenyataan fisik gedung yang tidak bisa ditebak dari luar.
         * Menebaknya berarti menyediakan pilihan yang tidak ada di
         * gedungnya, lalu jadwal operasi menunjuk ruang yang tidak pernah
         * dibangun.
         */
        $this->assertSame(0, OperatingRoom::query()->count());
        $this->assertTrue($this->directory->activeOperatingRooms()->isEmpty());
    }

    // ------------------------------------------------------------ pembantu

    private function unit(string $kode, string $nama, string $jenis = 'penunjang'): Unit
    {
        return Unit::query()->create([
            'code' => $kode, 'name' => $nama, 'kind' => $jenis, 'is_active' => true,
        ]);
    }

    private function praktisi(string $kode, string $nama): Practitioner
    {
        return Practitioner::query()->create([
            'code' => $kode, 'name' => $nama, 'is_active' => true,
            'active_from' => '2025-01-01',
        ]);
    }
}
