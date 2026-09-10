<?php

namespace Tests\Feature\Pharmacy;

use App\Modules\Pharmacy\Models\DrugMarkup;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Markup harga jual obat per penjamin (domain U, `set_harga_obat_ralan` +
 * `set_harga_obat_ranap`).
 *
 * Yang dikunci:
 *
 * 1. SATU TABEL, BUKAN DUA — perhitungan harga tidak perlu tahu lebih dulu
 *    ia melayani ralan atau ranap sebelum memilih tabel mana yang dibaca.
 * 2. YANG LEBIH KHUSUS MENANG — markup ICU tidak tertimpa baris umum.
 * 3. BELUM DITETAPKAN BUKAN NOL.
 * 4. SATU MARKUP BERJALAN per penjamin per kelas, termasuk untuk baris
 *    "semua kelas" yang NULL-nya lolos indeks unik biasa.
 */
class DrugMarkupTest extends TestCase
{
    use RefreshDatabase;

    private const BPJS = 1;

    private const UMUM = 2;

    #[Test]
    public function satu_tabel_melayani_rawat_jalan_dan_rawat_inap(): void
    {
        // Baris tanpa kelas = `set_harga_obat_ralan` Khanza.
        $this->markup(self::BPJS, null, 10, '2026-01-01');

        // Baris berkelas = `set_harga_obat_ranap` Khanza.
        $this->markup(self::BPJS, 'kelas-1', 15, '2026-01-01');

        /*
         * Pada Khanza keduanya tabel terpisah, jadi setiap perhitungan
         * harga harus tahu lebih dulu ia melayani ralan atau ranap. Yang
         * lupa salah satunya tidak melempar galat — ia jatuh ke harga
         * bawaan dan menagih angka yang salah dengan tenang.
         */
        $ralan = DrugMarkup::berlaku(self::BPJS, null, '2026-03-01');
        $ranap = DrugMarkup::berlaku(self::BPJS, 'kelas-1', '2026-03-01');

        $this->assertSame('10.00', $ralan->markup_percent);
        $this->assertSame('15.00', $ranap->markup_percent);
    }

    #[Test]
    public function markup_berkelas_mengalahkan_markup_semua_kelas(): void
    {
        $this->markup(self::BPJS, null, 10, '2026-01-01');
        $this->markup(self::BPJS, 'icu', 25, '2026-01-01');

        // Kalau yang umum menang, markup ICU yang sengaja ditetapkan berbeda
        // akan tertimpa baris yang kebetulan dibuat belakangan.
        $this->assertSame('25.00', DrugMarkup::berlaku(self::BPJS, 'icu', '2026-03-01')->markup_percent);

        // Kelas yang tidak punya barisnya sendiri jatuh ke yang umum —
        // itu memang gunanya baris "semua kelas".
        $this->assertSame('10.00', DrugMarkup::berlaku(self::BPJS, 'kelas-3', '2026-03-01')->markup_percent);
    }

    #[Test]
    public function icu_dan_isolasi_punya_tempat_yang_di_khanza_tidak_ada(): void
    {
        /*
         * enum kelas `set_harga_obat_ranap` Khanza berisi Kelas 1/2/3/
         * Utama/VIP/VVIP — ICU dan ISOLASI tidak ada sama sekali, padahal
         * keduanya kelas rawat yang tarif obatnya justru paling sering
         * berbeda.
         */
        foreach (['icu', 'isolasi'] as $kelas) {
            $this->markup(self::BPJS, $kelas, 30, '2026-01-01');

            $this->assertSame('30.00', DrugMarkup::berlaku(self::BPJS, $kelas, '2026-03-01')->markup_percent);
        }
    }

    #[Test]
    public function markup_dibaca_menurut_tanggal(): void
    {
        $lama = $this->markup(self::UMUM, null, 10, '2026-01-01');
        $lama->update(['effective_until' => '2026-06-30']);
        $this->markup(self::UMUM, null, 20, '2026-07-01');

        // Tagihan lampau tidak boleh berubah sendiri saat markupnya
        // disesuaikan.
        $this->assertSame('10.00', DrugMarkup::berlaku(self::UMUM, null, '2026-03-15')->markup_percent);
        $this->assertSame('20.00', DrugMarkup::berlaku(self::UMUM, null, '2026-08-15')->markup_percent);
    }

    #[Test]
    public function belum_ditetapkan_bukan_nol(): void
    {
        /*
         * Menagih dengan markup nol yang tidak pernah diputuskan berarti
         * menjual obat sesuai harga dasar tanpa ada yang memutuskan begitu
         * — rumah sakit kehilangan marjin tanpa satu pun keputusan.
         */
        $this->assertNull(DrugMarkup::berlaku(self::BPJS, null, '2026-03-01'));

        // Nol yang DITETAPKAN adalah keputusan yang sah, dan harus terbedakan.
        $this->markup(self::BPJS, null, 0, '2026-01-01');

        $this->assertSame('0.00', DrugMarkup::berlaku(self::BPJS, null, '2026-03-01')->markup_percent);
    }

    #[Test]
    public function dua_markup_semua_kelas_berjalan_ditolak_basis_data(): void
    {
        $this->markup(self::BPJS, null, 10, '2026-01-01');

        /*
         * NULL tidak sama dengan NULL pada indeks unik PostgreSQL, jadi
         * indeks biasa akan MELOLOSKAN dua baris "semua kelas" — dan itu
         * justru baris yang paling sering dipakai. Karena itu ada indeks
         * parsial kedua khusus untuk room_class IS NULL.
         */
        $this->expectException(QueryException::class);

        $this->markup(self::BPJS, null, 20, '2026-07-01');
    }

    #[Test]
    public function dua_markup_berjalan_untuk_kelas_yang_sama_ditolak(): void
    {
        $this->markup(self::BPJS, 'kelas-1', 10, '2026-01-01');

        $this->expectException(QueryException::class);

        $this->markup(self::BPJS, 'kelas-1', 20, '2026-07-01');
    }

    #[Test]
    public function markup_negatif_ditolak(): void
    {
        $this->expectException(QueryException::class);

        $this->markup(self::BPJS, null, -5, '2026-01-01');
    }

    private function markup(int $payerId, ?string $kelas, float $persen, string $sejak): DrugMarkup
    {
        return DrugMarkup::query()->create([
            'payer_id' => $payerId,
            'payer_code' => 'PJ'.$payerId,
            'room_class' => $kelas,
            'markup_percent' => $persen,
            'effective_from' => $sejak,
        ]);
    }
}
