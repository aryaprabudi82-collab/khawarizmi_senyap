<?php

namespace Tests\Feature\Billing;

use App\Modules\Billing\Models\CashierShift;
use App\Modules\Billing\Models\CashierShiftClosing;
use App\Modules\Billing\Models\Invoice;
use App\Modules\Billing\Models\Payment;
use App\Modules\Billing\Services\CashierClosingService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

/**
 * Penutupan shift kasir (Khanza `closing_kasir`, domain U).
 *
 * KHANZA TIDAK MENUTUP APA PUN. `closing_kasir` berisi tepat tiga kolom —
 * shift, jam masuk, jam pulang — jadi ia jadwal shift, bukan penutupan.
 * Tidak ada hitungan uang laci dan tidak ada selisih, sehingga seorang
 * kasir bisa mengakhiri shift dengan jumlah uang berapa pun dan tidak ada
 * apa pun yang membandingkannya dengan yang tercatat sistem.
 *
 * Yang dikunci:
 *
 * 1. SELISIH DIHITUNG, tidak pernah disimpan.
 * 2. JUMLAH TERCATAT DIBEKUKAN saat menutup — pengecualian yang disengaja.
 * 3. SELISIH TIDAK MEMBLOKIR, TAPI WAJIB BERALASAN.
 * 4. NON-TUNAI TIDAK IKUT hitungan laci.
 * 5. SHIFT MALAM yang menyeberang tengah malam terhitung benar.
 */
class CashierClosingTest extends TestCase
{
    use RefreshDatabase;

    private CashierClosingService $closing;

    private int $urut = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $this->closing = app(CashierClosingService::class);
    }

    #[Test]
    public function selisih_dihitung_bukan_disimpan(): void
    {
        $shift = $this->shift('PAGI', '07:00', '14:00');
        $this->bayar(500_000, 'tunai', '2026-09-09 09:00');

        $tutup = $this->closing->close(
            $shift, '2026-09-09', 'Kasir Uji', 480_000,
            varianceReason: 'Kurang Rp20.000, sedang ditelusuri ke bukti setor.'
        );

        $this->assertSame('-20000.00', $tutup->selisih());
        $this->assertFalse($tutup->cocok());

        /*
         * Tidak ada kolom selisih. Selisih yang dibekukan akan salah begitu
         * salah satu sisinya dikoreksi — dan selisih kas yang salah adalah
         * persis angka yang dipakai menuduh orang.
         */
        $this->assertArrayNotHasKey('variance', $tutup->getAttributes());
        $this->assertArrayNotHasKey('selisih', $tutup->getAttributes());

        // Dikoreksi hitungannya, selisihnya ikut.
        $tutup->update(['counted_cash' => 500_000, 'variance_reason' => null]);

        $this->assertSame('0.00', $tutup->fresh()->selisih());
        $this->assertTrue($tutup->fresh()->cocok());
    }

    #[Test]
    public function jumlah_tercatat_dibekukan_saat_menutup(): void
    {
        $shift = $this->shift('PAGI', '07:00', '14:00');
        $this->bayar(500_000, 'tunai', '2026-09-09 09:00');

        $tutup = $this->closing->close($shift, '2026-09-09', 'Kasir Uji', 500_000);

        $this->assertTrue($tutup->cocok());

        /*
         * Pembayaran yang masuk SETELAH penutupan tidak boleh mengubah
         * angka pembanding secara surut: petugas yang sudah menandatangani
         * selisih nol tidak boleh tiba-tiba punya selisih yang tidak pernah
         * ia lihat. Yang dibekukan adalah apa yang TERLIHAT saat penutupan.
         */
        $this->bayar(300_000, 'tunai', '2026-09-09 10:00');

        $this->assertSame('500000.00', $tutup->fresh()->recorded_cash);
        $this->assertTrue($tutup->fresh()->cocok());
    }

    #[Test]
    public function selisih_wajib_beralasan_tapi_tidak_memblokir(): void
    {
        $shift = $this->shift('PAGI', '07:00', '14:00');
        $this->bayar(500_000, 'tunai', '2026-09-09 09:00');

        try {
            $this->closing->close($shift, '2026-09-09', 'Kasir Uji', 450_000);
            $this->fail('Selisih tanpa penjelasan seharusnya ditolak.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('wajib disertai penjelasan', $e->getMessage());
        }

        /*
         * Tapi begitu ada penjelasannya, penutupan JALAN — tidak ditahan
         * sampai selisihnya nol. Menahannya akan membuat petugas mengetik
         * angka yang mencocokkan alih-alih angka yang ia hitung, dan sejak
         * itu seluruh catatan kas jadi karangan yang rapi.
         */
        $tutup = $this->closing->close(
            $shift, '2026-09-09', 'Kasir Uji', 450_000,
            varianceReason: 'Uang kembalian kurang; sudah dilaporkan ke penyelia.'
        );

        $this->assertSame('-50000.00', $tutup->selisih());
        $this->assertNotNull($tutup->variance_reason);
    }

    #[Test]
    public function basis_data_menolak_selisih_tanpa_alasan_walau_lewat_penulisan_langsung(): void
    {
        $shift = $this->shift('PAGI', '07:00', '14:00');

        // Aturannya ditegakkan CHECK juga, bukan hanya service: impor dan
        // perbaikan data manual lewat di bawah kode aplikasi.
        $this->expectException(QueryException::class);

        CashierShiftClosing::query()->create([
            'closing_number' => 'CLS-UJI-1',
            'shift_id' => $shift->id,
            'business_date' => '2026-09-09',
            'cashier_name' => 'Kasir Uji',
            'window_from' => now(),
            'window_until' => now()->addHours(7),
            'closed_at' => now(),
            'counted_cash' => 450_000,
            'recorded_cash' => 500_000,
        ]);
    }

    #[Test]
    public function non_tunai_tidak_ikut_hitungan_laci(): void
    {
        $shift = $this->shift('PAGI', '07:00', '14:00');

        $this->bayar(500_000, 'tunai', '2026-09-09 09:00');
        $this->bayar(750_000, 'qris', '2026-09-09 10:00');
        $this->bayar(250_000, 'debit', '2026-09-09 11:00');

        $tutup = $this->closing->close($shift, '2026-09-09', 'Kasir Uji', 500_000);

        /*
         * Kartu dan QRIS tidak pernah masuk laci. Memasukkannya ke hitungan
         * selisih akan menghasilkan selisih sebesar seluruh transaksi
         * non-tunai setiap hari — dan kasir yang bekerja benar akan tampak
         * kekurangan uang sejuta setiap shift.
         */
        $this->assertSame('500000.00', $tutup->recorded_cash);
        $this->assertSame('1000000.00', $tutup->recorded_noncash);
        $this->assertTrue($tutup->cocok());
    }

    #[Test]
    public function shift_malam_yang_menyeberang_tengah_malam_terhitung_benar(): void
    {
        $malam = $this->shift('MALAM', '22:00', '06:00', menyeberang: true);

        $this->bayar(200_000, 'tunai', '2026-09-09 23:30');
        $this->bayar(300_000, 'tunai', '2026-09-10 02:15');

        $tutup = $this->closing->close($malam, '2026-09-09', 'Kasir Malam', 500_000);

        /*
         * Tanpa penanganan penyeberangan tengah malam, rentang 22:00–06:00
         * terbaca sebagai rentang kosong: tidak satu pun pembayaran masuk
         * hitungan, dan kasir malam SELALU tampak kelebihan uang sebesar
         * seluruh penerimaannya.
         */
        $this->assertSame('500000.00', $tutup->recorded_cash);
        $this->assertTrue($tutup->cocok());
    }

    #[Test]
    public function pembayaran_yang_dibatalkan_tidak_ikut_hitungan_laci(): void
    {
        $shift = $this->shift('PAGI', '07:00', '14:00');

        $this->bayar(500_000, 'tunai', '2026-09-09 09:00');
        $this->bayar(200_000, 'tunai', '2026-09-09 10:00', dibatalkan: true);

        $tutup = $this->closing->close($shift, '2026-09-09', 'Kasir Uji', 500_000);

        /*
         * Uang pembayaran yang dibatalkan sudah dikembalikan ke pasien, jadi
         * ia tidak ada di laci. Memasukkannya berarti menuntut kasir
         * mempertanggungjawabkan uang yang justru sudah benar ia keluarkan,
         * dan setiap pembatalan akan tampak sebagai kekurangan kas sebesar
         * nilainya.
         */
        $this->assertSame('500000.00', $tutup->recorded_cash);
        $this->assertTrue($tutup->cocok());
    }

    #[Test]
    public function pembayaran_di_luar_rentang_shift_tidak_ikut(): void
    {
        $pagi = $this->shift('PAGI', '07:00', '14:00');

        $this->bayar(500_000, 'tunai', '2026-09-09 09:00');
        $this->bayar(400_000, 'tunai', '2026-09-09 16:00');   // shift sore

        $tutup = $this->closing->close($pagi, '2026-09-09', 'Kasir Pagi', 500_000);

        $this->assertSame('500000.00', $tutup->recorded_cash);
    }

    #[Test]
    public function satu_penutupan_per_shift_per_hari_per_kasir(): void
    {
        $shift = $this->shift('PAGI', '07:00', '14:00');

        $this->closing->close($shift, '2026-09-09', 'Kasir Uji', 0, cashierId: 7);

        // Dua penutupan untuk satu shift berarti salah satunya menghitung
        // uang yang sama dua kali.
        $this->expectException(QueryException::class);

        $this->closing->close($shift, '2026-09-09', 'Kasir Uji', 0, cashierId: 7);
    }

    #[Test]
    public function shift_jadi_baris_bukan_enum(): void
    {
        /*
         * `closing_kasir` Khanza mengunci empat shift di dalam tipe kolom
         * enum('Pagi','Siang','Sore','Malam'); rumah sakit yang membuka
         * shift kelima harus mengubah tipe kolom.
         */
        $this->shift('PAGI', '07:00', '14:00');
        $this->shift('SIANG', '14:00', '21:00');
        $this->shift('MALAM', '21:00', '07:00', menyeberang: true);
        $this->shift('SUBUH', '05:00', '07:00');   // yang kelima

        $this->assertSame(4, CashierShift::query()->count());
    }

    // ------------------------------------------------------------ pembantu

    private function shift(string $kode, string $mulai, string $selesai, bool $menyeberang = false): CashierShift
    {
        return CashierShift::query()->create([
            'code' => $kode,
            'name' => ucfirst(strtolower($kode)),
            'start_time' => $mulai,
            'end_time' => $selesai,
            'crosses_midnight' => $menyeberang,
            'is_active' => true,
        ]);
    }

    private function bayar(int $jumlah, string $metode, string $waktu, bool $dibatalkan = false): Payment
    {
        $n = ++$this->urut;

        $tagihan = Invoice::query()->create([
            'invoice_number' => 'INV-UJI-'.$n,
            'registration_id' => $n,
            'patient_id' => $n,
            'payer_id' => 1,
            'registration_number' => 'REG-'.$n,
            'patient_mrn' => 'RM-'.$n,
            'patient_name' => 'Pasien Uji '.$n,
            'payer_name' => 'Umum',
            'payer_kind' => 'umum',
            'payment_responsibility' => 'pasien',
            'total_amount' => $jumlah,
            'opened_at' => $waktu,
        ]);

        return Payment::query()->create([
            'payment_number' => 'PAY-UJI-'.$n,
            'invoice_id' => $tagihan->id,
            'amount' => $jumlah,
            'method' => $metode,
            'paid_at' => $waktu,
            'voided_at' => $dibatalkan ? $waktu : null,
            'void_reason' => $dibatalkan ? 'Salah input, dibatalkan di shift yang sama.' : null,
        ]);
    }
}
