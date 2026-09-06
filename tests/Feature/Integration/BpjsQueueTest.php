<?php

namespace Tests\Feature\Integration;

use App\Modules\Integration\Models\BpjsQueueRegistration;
use App\Modules\Integration\Services\Bpjs\FakeQueueClient;
use App\Modules\Integration\Services\Bpjs\QueueClient;
use App\Modules\Integration\Services\Bpjs\QueueService;
use App\Modules\Integration\Services\IntegrationException;
use App\Modules\Platform\Database\Seeders\PermissionCatalogSeeder;
use App\Modules\Platform\Database\Seeders\RoleSeeder;
use App\Modules\Platform\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Antrean Mobile JKN (domain L sisa).
 *
 * Kewajiban sejak 2022, dan yang dilihat pasien adalah data yang KITA
 * kirim. Yang paling perlu dikunci: tahap tidak boleh dikirim mundur, dan
 * tahap yang sudah berhasil tidak dikirim ulang — keduanya membuat catatan
 * waktu pelayanan di sisi BPJS berubah-ubah untuk pasien yang sama.
 */
class BpjsQueueTest extends TestCase
{
    use RefreshDatabase;

    private QueueService $antrean;
    private User $petugas;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([PermissionCatalogSeeder::class, RoleSeeder::class]);

        $this->app->bind(QueueClient::class, fn () => new FakeQueueClient());

        $this->antrean = app(QueueService::class);

        $this->petugas = User::query()->create([
            'username' => 'uji-integrasi-antrean', 'name' => 'Petugas Loket',
            'password' => 'password', 'is_active' => true,
        ]);
    }

    // ------------------------------------------------------------ pendaftaran

    #[Test]
    public function antrean_mobile_jkn_boleh_terdaftar_sebelum_pasien_datang(): void
    {
        $a = $this->daftar();

        $this->assertSame(BpjsQueueRegistration::TERDAFTAR, $a->status);
        $this->assertFalse($a->isLinked(), 'Belum ada kunjungan — pasien belum datang');
        $this->assertCount(1, $this->antrean->unlinked($this->hariIni()));
    }

    /**
     * Daftar antrean yang belum bertemu kunjungannya adalah yang paling
     * perlu terlihat di loket: peserta sudah mendaftar dari rumah, dan
     * tanpa daftar ini petugas tidak tahu siapa yang sedang ditunggu.
     */
    #[Test]
    public function antrean_hilang_dari_daftar_belum_tertaut_setelah_ditautkan(): void
    {
        $a = $this->daftar();

        $this->antrean->link($a, 123, '20260906-00001');

        $this->assertTrue($a->refresh()->isLinked());
        $this->assertCount(0, $this->antrean->unlinked($this->hariIni()));
    }

    #[Test]
    public function antrean_yang_sudah_tertaut_tidak_bisa_ditautkan_lagi(): void
    {
        $a = $this->antrean->link($this->daftar(), 123, '20260906-00001');

        $this->expectException(IntegrationException::class);
        $this->expectExceptionMessage('sudah tertaut');

        $this->antrean->link($a, 456, '20260906-00002');
    }

    /** Dua nomor antrean untuk orang yang sama membuat salah satunya pasti terbuang. */
    #[Test]
    public function peserta_tidak_boleh_punya_dua_antrean_aktif_di_poli_yang_sama(): void
    {
        $this->daftar();

        $this->expectException(IntegrationException::class);
        $this->expectExceptionMessage('sudah punya antrean aktif');

        $this->daftar();
    }

    #[Test]
    public function peserta_boleh_punya_antrean_di_poli_berbeda(): void
    {
        $this->daftar('INT');
        $a = $this->daftar('ANA');

        $this->assertSame('ANA', $a->poly_code);
    }

    #[Test]
    public function antrean_yang_dibatalkan_membebaskan_slotnya(): void
    {
        $this->antrean->cancel($this->daftar(), 'pasien berhalangan');

        $baru = $this->daftar();

        $this->assertSame(BpjsQueueRegistration::TERDAFTAR, $baru->status);
    }

    #[Test]
    public function pembatalan_yang_ditolak_bpjs_tidak_mengubah_status(): void
    {
        $a = $this->daftar(kodeBooking: '8000-GANGGUAN');

        try {
            $this->antrean->cancel($a, 'coba batal');
            $this->fail('Seharusnya menolak');
        } catch (IntegrationException $e) {
            $this->assertStringContainsString('gangguan', $e->getMessage());
        }

        $this->assertSame(BpjsQueueRegistration::TERDAFTAR, $a->refresh()->status,
            'Status lokal tidak berubah kalau BPJS menolak pembatalannya');
    }

    // ------------------------------------------------------------------ tahap

    #[Test]
    public function tahap_terkirim_berurutan(): void
    {
        $a = $this->daftar();

        foreach ([1, 2, 3, 4] as $tahap) {
            $this->assertTrue($this->antrean->sendTask($a, $tahap)->success);
        }

        $this->assertSame([1, 2, 3, 4], $this->antrean->successfulTasks($a));
    }

    /**
     * INTI: mengirim tahap lebih awal setelah tahap lanjut berhasil membuat
     * catatan waktu pelayanan di sisi BPJS berjalan mundur, dan pasien
     * melihat antrean yang seolah kembali ke belakang.
     */
    #[Test]
    public function tahap_tidak_boleh_dikirim_mundur(): void
    {
        $a = $this->daftar();
        $this->antrean->sendTask($a, 1);
        $this->antrean->sendTask($a, 5);

        $this->expectException(IntegrationException::class);
        $this->expectExceptionMessage('tidak boleh berjalan mundur');

        $this->antrean->sendTask($a, 3);
    }

    /** Pengiriman ulang tahap yang sukses membuat waktu pelayanan berubah-ubah. */
    #[Test]
    public function tahap_yang_sudah_berhasil_tidak_dikirim_ulang(): void
    {
        $a = $this->daftar();
        $this->antrean->sendTask($a, 1);

        $this->expectException(IntegrationException::class);
        $this->expectExceptionMessage('sudah pernah dikirim dan berhasil');

        $this->antrean->sendTask($a, 1);
    }

    /** Yang GAGAL justru harus bisa diulang. */
    #[Test]
    public function tahap_yang_gagal_boleh_dikirim_ulang(): void
    {
        $a = $this->daftar(kodeBooking: '8000-GANGGUAN');

        $gagal = $this->antrean->sendTask($a, 1);
        $this->assertFalse($gagal->success);

        // Kode booking diperbaiki, lalu dikirim ulang.
        $a->update(['booking_code' => 'BK-BAIK']);

        $ulang = $this->antrean->sendTask($a->refresh(), 1);
        $this->assertTrue($ulang->success);
        $this->assertCount(2, $this->antrean->tasks($a), 'Percobaan gagal tetap tersimpan');
    }

    #[Test]
    public function tahap_di_luar_satu_sampai_tujuh_ditolak(): void
    {
        $a = $this->daftar();

        $this->expectException(IntegrationException::class);
        $this->expectExceptionMessage('BPJS mengenal 1 sampai 7');

        $this->antrean->sendTask($a, 9);
    }

    #[Test]
    public function antrean_batal_tidak_bisa_dikirimi_tahap(): void
    {
        $a = $this->antrean->cancel($this->daftar(), 'berhalangan');

        $this->expectException(IntegrationException::class);
        $this->expectExceptionMessage('dibatalkan tidak bisa dikirimi tahap');

        $this->antrean->sendTask($a, 1);
    }

    // ---------------------------------------------------------------- laporan

    /**
     * Tahap yang tidak terkirim membuat pasien melihat antrean yang macet
     * di Mobile JKN meski di rumah sakit sudah dilayani.
     */
    #[Test]
    public function antrean_dengan_tahap_belum_lengkap_dilaporkan(): void
    {
        $a = $this->daftar();
        foreach ([1, 2, 3] as $t) {
            $this->antrean->sendTask($a, $t);
        }

        $belum = $this->antrean->incompleteTasks($this->hariIni());

        $this->assertCount(1, $belum);
        $this->assertSame(3, (int) $belum->first()->tahap_terkirim);
    }

    #[Test]
    public function antrean_lengkap_tujuh_tahap_tidak_lagi_dilaporkan(): void
    {
        $a = $this->daftar();
        foreach (range(1, 7) as $t) {
            $this->antrean->sendTask($a, $t);
        }

        $this->assertCount(0, $this->antrean->incompleteTasks($this->hariIni()));
    }

    #[Test]
    public function antrean_bisa_disaring_per_poli(): void
    {
        $this->daftar('INT');
        $this->daftar('ANA');

        $this->assertCount(2, $this->antrean->queueFor($this->hariIni()));
        $this->assertCount(1, $this->antrean->queueFor($this->hariIni(), 'INT'));
    }

    // ------------------------------------------------------------------ bantu

    private function hariIni(): string
    {
        return now()->toDateString();
    }

    private function daftar(string $poli = 'INT', string $kodeBooking = 'BK-001'): BpjsQueueRegistration
    {
        return $this->antrean->register([
            'card_number' => '0001234567890',
            'service_date' => $this->hariIni(),
            'poly_code' => $poli,
            'booking_code' => $kodeBooking,
            'patient_name' => 'Peserta Uji',
            'queue_number' => 1,
        ], $this->petugas->id);
    }
}
