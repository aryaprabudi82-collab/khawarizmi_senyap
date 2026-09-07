<?php

namespace Tests\Feature\Integration;

use App\Modules\Integration\Models\BpjsApotekPrescription;
use App\Modules\Integration\Models\BpjsMemberLookup;
use App\Modules\Integration\Services\Bpjs\ApotekPrescriptionService;
use App\Modules\Integration\Services\IntegrationException;
use App\Modules\Platform\Database\Seeders\PermissionCatalogSeeder;
use App\Modules\Platform\Database\Seeders\RoleSeeder;
use App\Modules\Platform\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Resep apotek BPJS & resep iterasi (domain L item M).
 *
 * Yang paling perlu dikunci:
 *
 * 1. RESEP ITERASI ADALAH SATU RESEP YANG DITEBUS BERKALI-KALI. Mencatat
 *    tiap penebusan sebagai resep baru mengklaim tiga resep padahal dokter
 *    menulis satu, dan membuat jatahnya bisa terlampaui tanpa terlihat.
 * 2. JATAH ITERASI TIDAK BISA DILAMPAUI, dijaga service DAN basis data.
 * 3. ISI ITERASI DISALIN DARI RESEP INDUK — iterasi menebus resep yang
 *    SAMA, dan menyusun ulang isinya membuka celah penebusan yang berbeda
 *    dari yang diresepkan dokter.
 */
class BpjsApotekPrescriptionTest extends TestCase
{
    use RefreshDatabase;

    private ApotekPrescriptionService $apotek;
    private User $petugas;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([PermissionCatalogSeeder::class, RoleSeeder::class]);

        $this->apotek = app(ApotekPrescriptionService::class);

        $this->petugas = User::query()->create([
            'username' => 'uji-apotek-bpjs', 'name' => 'Apoteker Uji',
            'password' => 'password', 'is_active' => true,
        ]);
    }

    // ----------------------------------------------------------- pencarian SEP

    #[Test]
    public function pencarian_sep_apotek_tercatat_di_tabel_pencarian_yang_sama(): void
    {
        $hasil = $this->apotek->findSep('0301R0012345', $this->petugas->id);

        $this->assertTrue($hasil->found);
        $this->assertSame('sep-apotek', $hasil->lookup_type);
        $this->assertNotNull($hasil->card_number);
        $this->assertStringContainsString('Penyakit Dalam', $hasil->summary);

        // Satu tabel untuk seluruh pencarian — lihat item J.
        $this->assertSame(1, BpjsMemberLookup::query()->count());
    }

    #[Test]
    public function sep_yang_tidak_ditemukan_tidak_dihitung_ditemukan(): void
    {
        $hasil = $this->apotek->findSep('X301R0012345');

        $this->assertFalse($hasil->found);
        $this->assertNull($hasil->card_number);
    }

    #[Test]
    public function gangguan_apotek_online_dibedakan_dari_sep_tidak_ada(): void
    {
        $gangguan = $this->apotek->findSep('Z301R0012345');

        $this->assertFalse($gangguan->found);
        $this->assertStringContainsString('gangguan', $gangguan->error_message);
    }

    // -------------------------------------------------------------- resep

    #[Test]
    public function resep_terkirim_dengan_nomor_apotek_dari_bpjs(): void
    {
        $resep = $this->apotek->send($this->resep(), $this->petugas->id);

        $this->assertSame(BpjsApotekPrescription::TERKIRIM, $resep->status);
        $this->assertNotNull($resep->bpjs_prescription_number);
        $this->assertTrue($resep->isParent());
        $this->assertSame('75000.00', $resep->total_amount);
    }

    #[Test]
    public function resep_tanpa_rincian_obat_ditolak(): void
    {
        $this->expectException(IntegrationException::class);
        $this->expectExceptionMessage('tanpa rincian obat');

        $this->apotek->send($this->resep(['items' => []]));
    }

    #[Test]
    public function resep_tanpa_sep_ditolak(): void
    {
        $this->expectException(IntegrationException::class);
        $this->expectExceptionMessage('Nomor SEP wajib diisi');

        $this->apotek->send($this->resep(['sep_number' => '  ']));
    }

    #[Test]
    public function jatah_iterasi_melebihi_ketentuan_bpjs_ditolak(): void
    {
        $this->expectException(IntegrationException::class);
        $this->expectExceptionMessage('membatasi iterasi resep sebanyak 3');

        $this->apotek->send($this->resep(['is_iterative' => true, 'iteration_allowed' => 4]));
    }

    #[Test]
    public function jatah_iterasi_pada_resep_biasa_ditolak(): void
    {
        $this->expectException(IntegrationException::class);
        $this->expectExceptionMessage('hanya berlaku untuk resep iterasi');

        $this->apotek->send($this->resep(['is_iterative' => false, 'iteration_allowed' => 2]));
    }

    #[Test]
    public function kegagalan_pengiriman_tetap_tercatat(): void
    {
        $resep = $this->apotek->send($this->resep(['sep_number' => 'Z301R0012345']));

        $this->assertSame(BpjsApotekPrescription::GAGAL, $resep->status);
        $this->assertNull($resep->bpjs_prescription_number);
        $this->assertStringContainsString('gangguan', $resep->response_message);
    }

    // ------------------------------------------------------------- iterasi

    /**
     * ATURAN PERTAMA: tiga penebusan atas SATU resep, bukan tiga resep.
     */
    #[Test]
    public function tiga_penebusan_tetap_menunjuk_satu_resep_induk(): void
    {
        $induk = $this->resepIteratif(3);

        $pertama = $this->apotek->redeem($induk, $this->petugas->id);
        $kedua = $this->apotek->redeem($induk->refresh());
        $ketiga = $this->apotek->redeem($induk->refresh());

        $this->assertSame(1, $pertama->iteration_index);
        $this->assertSame(2, $kedua->iteration_index);
        $this->assertSame(3, $ketiga->iteration_index);

        foreach ([$pertama, $kedua, $ketiga] as $iterasi) {
            $this->assertSame($induk->id, $iterasi->parent_id);
            $this->assertFalse($iterasi->isParent());
        }

        // Satu resep induk, tiga penebusan.
        $this->assertSame(1, BpjsApotekPrescription::query()->where('iteration_index', 0)->count());
        $this->assertSame(3, $induk->refresh()->iterations()->count());
    }

    /**
     * ATURAN KEDUA: jatah tidak bisa dilampaui.
     */
    #[Test]
    public function penebusan_melebihi_jatah_ditolak(): void
    {
        $induk = $this->resepIteratif(2);

        $this->apotek->redeem($induk);
        $this->apotek->redeem($induk->refresh());

        $this->expectException(IntegrationException::class);
        $this->expectExceptionMessage('sudah habis');

        $this->apotek->redeem($induk->refresh());
    }

    /** Dijaga basis data juga, bukan cuma service. */
    #[Test]
    public function basis_data_menolak_dua_penebusan_dengan_urutan_yang_sama(): void
    {
        $induk = $this->resepIteratif(2);
        $pertama = $this->apotek->redeem($induk);

        $this->expectException(QueryException::class);

        BpjsApotekPrescription::query()->create(
            collect($pertama->getAttributes())->except(['id', 'created_at', 'updated_at'])->all()
        );
    }

    /**
     * ATURAN KETIGA: iterasi menebus resep yang SAMA.
     */
    #[Test]
    public function isi_iterasi_disalin_dari_resep_induk(): void
    {
        $induk = $this->resepIteratif(2);

        $iterasi = $this->apotek->redeem($induk);

        $this->assertSame($induk->items, $iterasi->items);
        $this->assertSame($induk->total_amount, $iterasi->total_amount);
        $this->assertSame($induk->sep_number, $iterasi->sep_number);
    }

    #[Test]
    public function resep_biasa_tidak_bisa_ditebus_ulang(): void
    {
        $resep = $this->apotek->send($this->resep());

        $this->expectException(IntegrationException::class);
        $this->expectExceptionMessage('bukan resep iterasi');

        $this->apotek->redeem($resep);
    }

    #[Test]
    public function iterasi_tidak_bisa_ditebus_dari_iterasi(): void
    {
        $induk = $this->resepIteratif(3);
        $iterasi = $this->apotek->redeem($induk);

        $this->expectException(IntegrationException::class);
        $this->expectExceptionMessage('hanya bisa dilakukan atas resep induk');

        $this->apotek->redeem($iterasi);
    }

    #[Test]
    public function resep_yang_gagal_terkirim_tidak_bisa_ditebus(): void
    {
        $gagal = $this->apotek->send($this->resep([
            'sep_number' => 'Z301R0012345', 'is_iterative' => true, 'iteration_allowed' => 2,
        ]));

        $this->expectException(IntegrationException::class);
        $this->expectExceptionMessage('Hanya resep yang terkirim');

        $this->apotek->redeem($gagal);
    }

    /** Daftar inilah yang menunjukkan siapa yang masih boleh menebus. */
    #[Test]
    public function daftar_iterasi_tertunda_menyusut_setiap_penebusan(): void
    {
        $induk = $this->resepIteratif(2);

        $this->assertCount(1, $this->apotek->pendingIterations());

        $this->apotek->redeem($induk);
        $this->assertCount(1, $this->apotek->pendingIterations());

        $this->apotek->redeem($induk->refresh());
        $this->assertCount(0, $this->apotek->pendingIterations());
    }

    #[Test]
    public function sisa_jatah_dihitung_dari_penebusan_yang_tidak_dibatalkan(): void
    {
        $induk = $this->resepIteratif(3);

        $pertama = $this->apotek->redeem($induk);
        $this->assertSame(2, $induk->refresh()->remainingIterations());

        $pertama->update(['status' => BpjsApotekPrescription::BATAL]);
        $this->assertSame(3, $induk->refresh()->remainingIterations());
    }

    // ------------------------------------------------------------------ bantu

    private function resep(array $ubah = []): array
    {
        return array_merge([
            'sep_number' => '0301R0012345',
            'card_number' => '0001234567890',
            'patient_name' => 'Peserta PRB Uji',
            'apotek_code' => 'APT01',
            'prescriber_name' => 'dr. Uji',
            'prescribed_on' => now()->toDateString(),
            'items' => [
                ['code' => 'OBT-01', 'name' => 'Metformin 500 mg', 'qty' => 60, 'subtotal' => 30000],
                ['code' => 'OBT-02', 'name' => 'Glimepirid 2 mg', 'qty' => 30, 'subtotal' => 45000],
            ],
        ], $ubah);
    }

    private function resepIteratif(int $jatah): BpjsApotekPrescription
    {
        return $this->apotek->send($this->resep([
            'is_iterative' => true,
            'iteration_allowed' => $jatah,
        ]), $this->petugas->id);
    }
}
