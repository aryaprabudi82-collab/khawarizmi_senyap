<?php

namespace Tests\Feature\Clinical;

use App\Modules\Catalog\Models\FluidItem;
use App\Modules\Catalog\Models\Payer;
use App\Modules\Clinical\Models\FluidBalanceEntry;
use App\Modules\Clinical\Models\NursingNote;
use App\Modules\Clinical\Services\ClinicalException;
use App\Modules\Clinical\Services\FluidBalanceService;
use App\Modules\Clinical\Services\NursingNoteService;
use App\Modules\Encounter\Models\Registration;
use App\Modules\Encounter\Services\RegistrationService;
use App\Modules\Identity\Services\PatientRegistry;
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
 * Keseimbangan cairan & catatan keperawatan (domain M item E).
 *
 * Yang paling perlu dikunci:
 *
 * 1. SALDO DIHITUNG, TIDAK DISIMPAN. Khanza menyimpan kolom `keseimbangan`
 *    di samping komponennya; komponen yang diperbaiki meninggalkan saldo
 *    basi yang tidak terlihat salah. Di sini akibatnya bukan laporan yang
 *    meleset — keseimbangan cairan yang keliru pada pasien gagal ginjal
 *    adalah keputusan klinis yang keliru.
 * 2. ARAH DISALIN DARI JENIS CAIRANNYA. Arah yang bisa dikirim terpisah
 *    membuka celah urine tercatat sebagai asupan.
 * 3. CATATAN KEPERAWATAN TIDAK BISA DITIMPA — koreksi ditulis sebagai
 *    catatan baru yang menyebut apa yang diralat.
 */
class FluidBalanceTest extends TestCase
{
    use RefreshDatabase;

    private FluidBalanceService $cairan;
    private NursingNoteService $catatan;
    private User $perawat;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([PermissionCatalogSeeder::class, RoleSeeder::class, ReferenceDataSeeder::class]);

        $this->cairan = app(FluidBalanceService::class);
        $this->catatan = app(NursingNoteService::class);

        $this->perawat = User::query()->create([
            'username' => 'uji-cairan', 'name' => 'Ns. Cairan',
            'password' => 'password', 'is_active' => true,
        ]);
    }

    // -------------------------------------------------------- keseimbangan

    #[Test]
    public function saldo_dihitung_dari_selisih_masuk_dan_keluar(): void
    {
        $registrasi = $this->daftarkan();

        $this->cairan->record($registrasi->id, 'infus', 1500, null, null, $this->perawat);
        $this->cairan->record($registrasi->id, 'minum', 500, null, null, $this->perawat);
        $this->cairan->record($registrasi->id, 'urine', 1200, null, null, $this->perawat);
        $this->cairan->record($registrasi->id, 'iwl', 400, null, null, $this->perawat);

        $saldo = $this->cairan->balance($registrasi->id);

        $this->assertSame(2000.0, $saldo->masuk);
        $this->assertSame(1600.0, $saldo->keluar);
        $this->assertSame(400.0, $saldo->balance);
    }

    /**
     * ATURAN PERTAMA: saldo tidak pernah disimpan, jadi memperbaiki
     * komponennya langsung mengubah saldonya.
     */
    #[Test]
    public function memperbaiki_komponen_langsung_mengubah_saldo(): void
    {
        $registrasi = $this->daftarkan();

        $this->cairan->record($registrasi->id, 'infus', 1500);
        $urine = $this->cairan->record($registrasi->id, 'urine', 1200);

        $this->assertSame(300.0, $this->cairan->balance($registrasi->id)->balance);

        // Urine ternyata salah ukur; barisnya dicabut.
        $this->cairan->remove($urine);

        $this->assertSame(1500.0, $this->cairan->balance($registrasi->id)->balance);
    }

    /** Tidak ada kolom saldo di basis data — hanya komponennya. */
    #[Test]
    public function tidak_ada_kolom_saldo_tersimpan(): void
    {
        $kolom = \Illuminate\Support\Facades\Schema::getColumnListing('clinical.fluid_balance_entries');

        $this->assertNotContains('balance', $kolom);
        $this->assertNotContains('keseimbangan', $kolom);
    }

    /**
     * ATURAN KEDUA: arah datang dari master, bukan dari pemanggil.
     */
    #[Test]
    public function arah_disalin_dari_jenis_cairannya(): void
    {
        $registrasi = $this->daftarkan();

        $infus = $this->cairan->record($registrasi->id, 'infus', 500);
        $urine = $this->cairan->record($registrasi->id, 'urine', 500);

        $this->assertSame(FluidBalanceEntry::MASUK, $infus->direction);
        $this->assertSame(FluidBalanceEntry::KELUAR, $urine->direction);
    }

    #[Test]
    public function volume_nol_atau_negatif_ditolak(): void
    {
        $registrasi = $this->daftarkan();

        $this->expectException(ClinicalException::class);
        $this->expectExceptionMessage('Arah masuk atau keluar ditentukan jenis cairannya');

        $this->cairan->record($registrasi->id, 'infus', -500);
    }

    /** Dijaga basis data juga: volume negatif membalik arah tanpa melanggar aturan. */
    #[Test]
    public function basis_data_menolak_volume_negatif(): void
    {
        $registrasi = $this->daftarkan();

        $this->expectException(QueryException::class);

        FluidBalanceEntry::query()->create([
            'registration_id' => $registrasi->id,
            'patient_id' => $registrasi->patient_id,
            'item_code' => 'infus',
            'item_name' => 'Infus',
            'direction' => FluidBalanceEntry::MASUK,
            'volume_ml' => -100,
            'recorded_at' => now(),
        ]);
    }

    #[Test]
    public function jenis_cairan_di_luar_master_ditolak(): void
    {
        $registrasi = $this->daftarkan();

        $this->expectException(ClinicalException::class);
        $this->expectExceptionMessage('tidak ada di master');

        $this->cairan->record($registrasi->id, 'air-kelapa', 300);
    }

    #[Test]
    public function jenis_cairan_nonaktif_ditolak(): void
    {
        $registrasi = $this->daftarkan();
        FluidItem::query()->where('code', 'minum')->update(['is_active' => false]);

        $this->expectException(ClinicalException::class);
        $this->expectExceptionMessage('sudah tidak aktif');

        $this->cairan->record($registrasi->id, 'minum', 200);
    }

    /**
     * Hemodialisa memakai tabel yang sama — di Khanza ia tabel kedua yang
     * isinya nyaris sama.
     */
    #[Test]
    public function jenis_hemodialisa_memakai_tabel_yang_sama(): void
    {
        $registrasi = $this->daftarkan();

        $this->cairan->record($registrasi->id, 'sisa-priming', 200);
        $this->cairan->record($registrasi->id, 'ultrafiltrasi', 1800);

        $saldo = $this->cairan->balance($registrasi->id);

        $this->assertSame(200.0, $saldo->masuk);
        $this->assertSame(1800.0, $saldo->keluar);
        $this->assertSame(-1600.0, $saldo->balance);
    }

    #[Test]
    public function daftar_jenis_disaring_konteks_perawatannya(): void
    {
        $umum = $this->cairan->items()->pluck('code')->all();
        $hd = $this->cairan->items('hemodialisa')->pluck('code')->all();

        $this->assertContains('sisa-priming', $umum, 'Tanpa penyaring, seluruh jenis ikut.');
        $this->assertContains('infus', $hd, 'Jenis umum tetap tersedia di hemodialisa.');
        $this->assertContains('ultrafiltrasi', $hd);
    }

    /** Saldo per hari — yang menentukan pemberian cairan besok. */
    #[Test]
    public function saldo_dihitung_per_hari(): void
    {
        $registrasi = $this->daftarkan();

        $this->cairan->record($registrasi->id, 'infus', 2000, now()->subDay());
        $this->cairan->record($registrasi->id, 'urine', 1500, now()->subDay());
        $this->cairan->record($registrasi->id, 'infus', 1000, now());

        $harian = $this->cairan->dailyBalance($registrasi->id);

        $this->assertCount(2, $harian);
        $this->assertSame(1000.0, $harian->first()->balance);
        $this->assertSame(500.0, $harian->last()->balance);
    }

    /** Saldo tanpa rincian adalah angka yang harus dipercaya begitu saja. */
    #[Test]
    public function saldo_disertai_rinciannya(): void
    {
        $registrasi = $this->daftarkan();

        $this->cairan->record($registrasi->id, 'infus', 500);
        $this->cairan->record($registrasi->id, 'infus', 500);
        $this->cairan->record($registrasi->id, 'urine', 700);

        $rincian = $this->cairan->balance($registrasi->id)->rincian;

        $this->assertSame(1000.0, $rincian['Infus']->volume_ml);
        $this->assertSame(2, $rincian['Infus']->jumlah_catatan);
    }

    // ---------------------------------------------------- catatan keperawatan

    #[Test]
    public function catatan_keperawatan_tersimpan_berikut_penulisnya(): void
    {
        $registrasi = $this->daftarkan();

        $catatan = $this->catatan->record(
            $registrasi->id,
            'Kompres hangat diberikan, pasien tampak lebih tenang.',
            NursingNote::IMPLEMENTASI,
            null,
            null,
            $this->perawat
        );

        $this->assertSame('Ns. Cairan', $catatan->recorded_by_name);
        $this->assertSame(NursingNote::IMPLEMENTASI, $catatan->kind);
    }

    #[Test]
    public function catatan_kosong_ditolak(): void
    {
        $this->expectException(ClinicalException::class);
        $this->expectExceptionMessage('wajib diisi');

        $this->catatan->record($this->daftarkan()->id, '   ');
    }

    #[Test]
    public function jenis_catatan_asing_ditolak(): void
    {
        $this->expectException(ClinicalException::class);
        $this->expectExceptionMessage('tidak dikenal');

        $this->catatan->record($this->daftarkan()->id, 'Isi.', 'laporan');
    }

    /**
     * ATURAN KETIGA: koreksi ditulis sebagai catatan BARU.
     */
    #[Test]
    public function ralat_menghasilkan_catatan_baru_bukan_menimpa(): void
    {
        $registrasi = $this->daftarkan();
        $asli = $this->catatan->record($registrasi->id, 'Suhu 39 derajat.', NursingNote::PENGAMATAN, null, null, $this->perawat);

        $ralat = $this->catatan->amend($asli, 'Suhu sebenarnya 38 derajat.', $this->perawat);

        $this->assertNotSame($asli->id, $ralat->id);
        $this->assertSame('Suhu 39 derajat.', $asli->refresh()->note);
        $this->assertStringContainsString('Ralat atas catatan', $ralat->note);
        $this->assertStringContainsString('Suhu sebenarnya 38', $ralat->note);
        $this->assertCount(2, $this->catatan->forRegistration($registrasi->id));
    }

    /** Catatan boleh berdiri sendiri — tidak semua menindaklanjuti masalah. */
    #[Test]
    public function catatan_tanpa_diagnosis_tetap_sah(): void
    {
        $catatan = $this->catatan->record($this->daftarkan()->id, 'Pasien mengeluh pusing saat bangun.', NursingNote::PENGAMATAN);

        $this->assertNull($catatan->nursing_diagnosis_id);
    }

    #[Test]
    public function catatan_pasien_berlaku_lintas_kunjungan(): void
    {
        $pasien = app(PatientRegistry::class)->register([
            'name' => 'Pasien Catatan Tetap', 'sex' => 'P', 'birth_date' => '1960-01-01',
        ]);

        $this->catatan->recordPatientNote($pasien->id, $pasien->medical_record_number, 'Vena sulit, gunakan jarum kecil.', true, $this->perawat);
        $this->catatan->recordPatientNote($pasien->id, $pasien->medical_record_number, 'Alamat sudah pindah.', false, $this->perawat);

        $daftar = $this->catatan->patientNotes($pasien->id);

        $this->assertCount(2, $daftar);
        // Yang ditandai penting muncul lebih dulu.
        $this->assertTrue($daftar->first()->is_alert);
    }

    // ------------------------------------------------------------------ bantu

    private function daftarkan(): Registration
    {
        static $urut = 0;
        $urut++;

        $pasien = app(PatientRegistry::class)->register([
            'name' => 'Pasien Cairan ' . $urut, 'sex' => 'L', 'birth_date' => '1968-02-02',
        ]);

        return app(RegistrationService::class)->register(
            patientId: $pasien->id,
            unitId: Unit::query()->where('code', 'POL-UMUM')->value('id'),
            payerId: Payer::query()->where('code', 'UMUM')->value('id'),
        );
    }
}
