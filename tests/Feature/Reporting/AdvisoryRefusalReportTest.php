<?php

namespace Tests\Feature\Reporting;

use App\Modules\Correspondence\Services\ConsentService;
use App\Modules\Platform\Database\Seeders\PermissionCatalogSeeder;
use App\Modules\Platform\Database\Seeders\RoleSeeder;
use App\Modules\Platform\Models\User;
use App\Modules\Reporting\Services\AncillaryReportService;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * laporan_tahunan_penolakan_anjuran_medis (domain J).
 *
 * YANG DIKUNCI DI SINI ADALAH DEFINISINYA, bukan bentuk tabelnya. Ada dua
 * cara membaca "penolakan anjuran medis" dan keduanya tampak masuk akal:
 *
 *   (a) surat yang JENISNYA 'penolakan-anjuran-medis'; atau
 *   (b) surat apa pun yang KEPUTUSANNYA 'menolak'.
 *
 * Yang dipakai adalah (b). Pasien yang menolak tindakan yang ditawarkan
 * mengisi surat berjenis 'tindakan' dengan keputusan menolak, dan pasien
 * yang pulang atas permintaan sendiri mengisi jenisnya sendiri lagi —
 * keduanya menolak anjuran medis. Definisi (a) akan menghasilkan angka
 * tahunan yang jauh lebih kecil, dan angka penolakan yang terlalu kecil
 * dibaca sebagai kabar baik, bukan sebagai kesalahan.
 */
class AdvisoryRefusalReportTest extends TestCase
{
    use RefreshDatabase;

    private AncillaryReportService $penunjang;

    private ConsentService $persetujuan;

    private User $petugas;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([PermissionCatalogSeeder::class, RoleSeeder::class, ReferenceDataSeeder::class]);

        $this->penunjang = app(AncillaryReportService::class);
        $this->persetujuan = app(ConsentService::class);

        $this->petugas = User::query()->create([
            'username' => 'uji-petugas-tu-penolakan', 'name' => 'Petugas TU',
            'password' => 'password', 'is_active' => true,
        ]);
    }

    /** INTI BERKAS INI: yang dihitung keputusannya, bukan jenis suratnya. */
    #[Test]
    public function penolakan_dihitung_dari_keputusan_bukan_jenis_surat(): void
    {
        $this->tolak('penolakan-anjuran-medis');
        $this->tolak('tindakan');
        $this->tolak('pulang-permintaan-sendiri');

        $hasil = $this->penunjang->advisoryRefusalYearly((int) now()->year);

        $this->assertSame(3, (int) $hasil->sum('penolakan'));
        $this->assertEqualsCanonicalizing(
            ['penolakan-anjuran-medis', 'tindakan', 'pulang-permintaan-sendiri'],
            $hasil->pluck('consent_type')->all()
        );
    }

    #[Test]
    public function persetujuan_yang_disetujui_tidak_ikut_terhitung(): void
    {
        $this->setujui('tindakan');
        $this->tolak('tindakan');

        $this->assertSame(1, (int) $this->penunjang->advisoryRefusalYearly((int) now()->year)->sum('penolakan'));
    }

    /**
     * Penolakan yang suratnya ditarik bukan penolakan yang bisa
     * dipertanggungjawabkan, jadi tidak ikut dilaporkan.
     */
    #[Test]
    public function surat_yang_dibatalkan_dikeluarkan_dari_hitungan(): void
    {
        $this->persetujuan->cancel($this->tolak('tindakan'));

        $this->assertSame(0, (int) $this->penunjang->advisoryRefusalYearly((int) now()->year)->sum('penolakan'));
    }

    #[Test]
    public function tahun_lain_tidak_ikut(): void
    {
        $this->tolak('tindakan');

        $this->assertCount(0, $this->penunjang->advisoryRefusalYearly((int) now()->year - 1));
    }

    /**
     * Satu pasien yang menolak berkali-kali tetap satu orang — kolom
     * pasien menjawab berapa ORANG, kolom penolakan berapa KALI.
     */
    #[Test]
    public function pasien_yang_menolak_berkali_kali_dihitung_satu_orang(): void
    {
        $this->tolak('tindakan', pasienId: 77);
        $this->tolak('tindakan', pasienId: 77);

        $baris = $this->penunjang->advisoryRefusalYearly((int) now()->year)->firstOrFail();

        $this->assertSame(2, (int) $baris->penolakan);
        $this->assertSame(1, (int) $baris->pasien);
    }

    // ------------------------------------------------------------- pembantu

    private function tolak(string $jenis, int $pasienId = 1)
    {
        return $this->terbitkan($jenis, 'menolak', $pasienId);
    }

    private function setujui(string $jenis, int $pasienId = 1)
    {
        return $this->terbitkan($jenis, 'setuju', $pasienId);
    }

    private function terbitkan(string $jenis, string $keputusan, int $pasienId)
    {
        $data = [
            'consent_type' => $jenis,
            'patient_id' => $pasienId,
            'patient_name' => 'Pasien Uji '.$pasienId,
            'procedure_description' => 'Uraian tindakan uji.',
            'decision' => $keputusan,
        ];

        if ($jenis === 'penolakan-anjuran-medis' && $keputusan === 'menolak') {
            $data['refusal_risk_explained'] = 'Risiko perburukan sudah dijelaskan kepada pasien dan keluarga.';
        }

        return $this->persetujuan->issue($data, $this->petugas->id);
    }
}
