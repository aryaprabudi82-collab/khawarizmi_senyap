<?php

namespace Tests\Feature\Integration;

use App\Modules\Integration\Models\BpjsMemberLookup;
use App\Modules\Integration\Services\Bpjs\MemberLookupService;
use App\Modules\Integration\Services\IntegrationException;
use App\Modules\Platform\Database\Seeders\PermissionCatalogSeeder;
use App\Modules\Platform\Database\Seeders\RoleSeeder;
use App\Modules\Platform\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Pencarian & riwayat peserta BPJS (domain L item J).
 *
 * Yang paling perlu dikunci:
 *
 * 1. "GANGGUAN" DAN "TIDAK TERDAFTAR" ADALAH DUA KEADAAN BERBEDA, dan
 *    menuntut tindakan berbeda dari petugas: yang satu dicoba lagi nanti,
 *    yang lain berarti pasien harus mengurus kepesertaannya.
 * 2. JAWABAN 200 DENGAN ISI KOSONG BUKAN "DITEMUKAN" — kalau dianggap
 *    ditemukan, layar menampilkan baris kosong yang tampak seperti data.
 * 3. BELUM PERNAH DICARI BUKAN "BELUM TERDAFTAR SIDIK JARI". Menyamakannya
 *    membuat petugas mengarahkan peserta mendaftar ulang tanpa sebab.
 */
class BpjsMemberLookupTest extends TestCase
{
    use RefreshDatabase;

    private MemberLookupService $peserta;
    private User $petugas;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([PermissionCatalogSeeder::class, RoleSeeder::class]);

        $this->peserta = app(MemberLookupService::class);

        $this->petugas = User::query()->create([
            'username' => 'uji-peserta-bpjs', 'name' => 'Petugas Loket Uji',
            'password' => 'password', 'is_active' => true,
        ]);
    }

    // ------------------------------------------------------------- cek NIK

    #[Test]
    public function pencarian_nik_menyimpan_nomor_kartu_dan_ringkasan_kepesertaan(): void
    {
        $hasil = $this->peserta->byNik('3175010101900001', null, $this->petugas->id);

        $this->assertTrue($hasil->found);
        $this->assertSame(BpjsMemberLookup::NIK, $hasil->lookup_type);
        $this->assertNotNull($hasil->card_number);
        $this->assertStringContainsString('kelas 3', $hasil->summary);
        $this->assertNull($hasil->error_message);
    }

    #[Test]
    public function nik_kosong_ditolak_sebelum_memanggil_vclaim(): void
    {
        $this->expectException(IntegrationException::class);
        $this->expectExceptionMessage('NIK wajib diisi');

        $this->peserta->byNik('   ');
    }

    /**
     * ATURAN PERTAMA: gangguan layanan bukan "peserta tidak terdaftar".
     */
    #[Test]
    public function gangguan_vclaim_dibedakan_dari_peserta_tidak_terdaftar(): void
    {
        $gangguan = $this->peserta->byNik('8175010101900001');
        $tidakAda = $this->peserta->byNik('9175010101900001');

        $this->assertFalse($gangguan->found);
        $this->assertStringContainsString('gangguan', $gangguan->error_message);

        $this->assertFalse($tidakAda->found);
        // Panggilannya berhasil — yang tidak ada pesertanya, bukan layanannya.
        $this->assertNull($tidakAda->error_message);
    }

    /**
     * ATURAN KEDUA: jawaban berhasil tapi kosong bukan "ditemukan".
     */
    #[Test]
    public function jawaban_berhasil_tapi_kosong_tidak_dihitung_ditemukan(): void
    {
        $hasil = $this->peserta->byNik('9175010101900001');

        $this->assertFalse($hasil->found);
        $this->assertNull($hasil->card_number);
    }

    /** Kegagalan tetap meninggalkan jejak — tanpa itu tak ada yang bisa menelusuri. */
    #[Test]
    public function pencarian_yang_gagal_tetap_tercatat(): void
    {
        $this->peserta->byNik('8175010101900001', null, $this->petugas->id);

        $this->assertSame(1, BpjsMemberLookup::query()->count());
        $this->assertSame('8175010101900001', BpjsMemberLookup::query()->first()->search_key);
    }

    // ---------------------------------------------------------------- SKDP

    #[Test]
    public function skdp_menyimpan_poli_dokter_dan_rencana_kontrolnya(): void
    {
        $hasil = $this->peserta->skdp('SKDP-0001', '0001234567890', $this->petugas->id);

        $this->assertTrue($hasil->found);
        $this->assertSame('0001234567890', $hasil->card_number);
        $this->assertStringContainsString('Penyakit Dalam', $hasil->summary);
        $this->assertStringContainsString('rencana kontrol', $hasil->summary);
    }

    #[Test]
    public function skdp_tanpa_nomor_kartu_ditolak(): void
    {
        $this->expectException(IntegrationException::class);
        $this->expectExceptionMessage('Nomor surat dan nomor kartu wajib');

        $this->peserta->skdp('SKDP-0001', '');
    }

    // ----------------------------------------------------- histori pelayanan

    #[Test]
    public function histori_pelayanan_menyimpan_barisnya_berikut_jumlahnya(): void
    {
        $hasil = $this->peserta->serviceHistory(
            '0001234567890',
            now()->subMonth()->toDateString(),
            now()->toDateString(),
            $this->petugas->id
        );

        $this->assertTrue($hasil->found);
        $this->assertSame(2, $hasil->result_count);
        $this->assertCount(2, $hasil->rows());
        $this->assertSame('RSUD Pembanding', $hasil->rows()[0]['ppkPelayanan']);
        $this->assertNotNull($hasil->period_from);
    }

    /**
     * Histori BPJS adalah pelayanan di fasilitas LAIN. Ia tidak boleh
     * masuk ke tabel kunjungan kita — rekam medis kita akan seolah memuat
     * pelayanan yang tidak pernah kita berikan.
     */
    #[Test]
    public function histori_pelayanan_tidak_membuat_kunjungan_di_sistem_kita(): void
    {
        $this->peserta->serviceHistory(
            '0001234567890',
            now()->subMonth()->toDateString(),
            now()->toDateString()
        );

        $this->assertSame(0, \App\Modules\Encounter\Models\Registration::query()->count());
    }

    #[Test]
    public function rentang_tanggal_terbalik_ditolak(): void
    {
        $this->expectException(IntegrationException::class);
        $this->expectExceptionMessage('tidak boleh mendahului');

        $this->peserta->serviceHistory(
            '0001234567890',
            now()->toDateString(),
            now()->subMonth()->toDateString()
        );
    }

    // ------------------------------------------------------------ fingerprint

    #[Test]
    public function status_sidik_jari_terdaftar_tercatat_berikut_tanggalnya(): void
    {
        $hasil = $this->peserta->fingerprint('0001234567890', null, $this->petugas->id);

        $this->assertTrue($hasil->found);
        $this->assertStringContainsString('Sudah terdaftar', $hasil->summary);
        $this->assertTrue($this->peserta->fingerprintRegistered('0001234567890'));
    }

    /** Yang BELUM terdaftar justru keadaan yang paling perlu terlihat. */
    #[Test]
    public function peserta_yang_belum_terdaftar_sidik_jari_dinyatakan_terang(): void
    {
        $hasil = $this->peserta->fingerprint('7001234567890');

        $this->assertTrue($hasil->found);
        $this->assertStringContainsString('BELUM terdaftar', $hasil->summary);
        $this->assertFalse($this->peserta->fingerprintRegistered('7001234567890'));
    }

    /**
     * ATURAN KETIGA: belum pernah dicari bukan "belum terdaftar".
     */
    #[Test]
    public function peserta_yang_belum_pernah_dicari_mengembalikan_null_bukan_false(): void
    {
        $this->assertNull($this->peserta->fingerprintRegistered('0009999999999'));
    }

    /**
     * Sistem ini tidak menyimpan data biometrik apa pun — yang dicatat cuma
     * status menurut BPJS.
     */
    #[Test]
    public function tidak_ada_data_biometrik_yang_disimpan(): void
    {
        $hasil = $this->peserta->fingerprint('0001234567890');

        $muatan = json_encode($hasil->raw_response);

        $this->assertStringNotContainsString('template', $muatan);
        $this->assertStringNotContainsString('minutiae', $muatan);
        $this->assertArrayNotHasKey('fingerprint_data', $hasil->getAttributes());
    }

    // ---------------------------------------------------------------- daftar

    #[Test]
    public function daftar_pencarian_terakhir_bisa_disaring_per_jenis(): void
    {
        $this->peserta->byNik('3175010101900001');
        $this->peserta->fingerprint('0001234567890');
        $this->peserta->fingerprint('7001234567890');

        $this->assertCount(3, $this->peserta->recent());
        $this->assertCount(2, $this->peserta->recent(BpjsMemberLookup::FINGERPRINT));
        $this->assertCount(1, $this->peserta->recent(BpjsMemberLookup::NIK));
    }
}
