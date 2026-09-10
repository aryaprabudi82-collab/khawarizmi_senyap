<?php

namespace Tests\Feature\Inpatient;

use App\Modules\Inpatient\Models\Room;
use App\Modules\Inpatient\Models\RoomDailyCharge;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Biaya harian tambahan per kamar (Khanza `biaya_harian`, domain U).
 *
 * `biaya_harian` berkunci (kd_kamar, NAMA_BIAYA) — memakai nilai yang boleh
 * berubah sebagai identitas. Memperbaiki ejaan sebuah pos biaya karena itu
 * bukan mengoreksi baris, melainkan membuat baris kedua, dan yang salah eja
 * tinggal ikut tertagih. Bentuk cacat yang sama dengan setting.nama_instansi
 * dan set_pjlab.
 */
class RoomDailyChargeTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function memperbaiki_nama_pos_biaya_tidak_melahirkan_pos_kedua(): void
    {
        $kamar = $this->kamar();

        $biaya = RoomDailyCharge::query()->create([
            'room_id' => $kamar->id,
            'code' => 'ASKEP',
            'name' => 'Asuhan Keperawtan',   // salah eja, seperti kejadian nyata
            'amount' => 50000,
            'effective_from' => '2026-01-01',
        ]);

        $biaya->update(['name' => 'Asuhan Keperawatan']);

        /*
         * Pada Khanza, ini akan menghasilkan DUA baris: yang salah eja
         * tinggal di sana ikut tertagih, dan rekap biaya harian menampilkan
         * dua pos untuk satu hal — total benar, rincian salah.
         */
        $this->assertSame(1, RoomDailyCharge::query()->where('room_id', $kamar->id)->count());
        $this->assertSame('Asuhan Keperawatan', $biaya->fresh()->name);
    }

    #[Test]
    public function satu_pos_biaya_berjalan_per_kamar_per_kode(): void
    {
        $kamar = $this->kamar();

        RoomDailyCharge::query()->create([
            'room_id' => $kamar->id, 'code' => 'ASKEP', 'name' => 'Asuhan Keperawatan',
            'amount' => 50000, 'effective_from' => '2026-01-01',
        ]);

        // Dua pos ASKEP berjalan bersamaan berarti pasien ditagih dua kali
        // untuk satu hal — dan tidak ada yang bisa menentukan mana yang sah.
        $this->expectException(QueryException::class);

        RoomDailyCharge::query()->create([
            'room_id' => $kamar->id, 'code' => 'ASKEP', 'name' => 'Asuhan Keperawatan',
            'amount' => 75000, 'effective_from' => '2026-07-01',
        ]);
    }

    #[Test]
    public function biaya_lama_ditutup_dengan_tanggal_bukan_dihapus(): void
    {
        $kamar = $this->kamar();

        $lama = RoomDailyCharge::query()->create([
            'room_id' => $kamar->id, 'code' => 'ASKEP', 'name' => 'Asuhan Keperawatan',
            'amount' => 50000, 'effective_from' => '2026-01-01',
        ]);

        $lama->update(['effective_until' => '2026-06-30']);

        $baru = RoomDailyCharge::query()->create([
            'room_id' => $kamar->id, 'code' => 'ASKEP', 'name' => 'Asuhan Keperawatan',
            'amount' => 75000, 'effective_from' => '2026-07-01',
        ]);

        // Hari rawat yang sudah lewat tetap harus bisa dihitung ulang dengan
        // biaya yang memang berlaku saat itu.
        $this->assertSame(2, RoomDailyCharge::query()->where('room_id', $kamar->id)->count());
        $this->assertFalse($lama->fresh()->masihBerlaku());
        $this->assertTrue($baru->masihBerlaku());
    }

    #[Test]
    public function periode_terbalik_ditolak_basis_data(): void
    {
        $kamar = $this->kamar();

        $this->expectException(QueryException::class);

        RoomDailyCharge::query()->create([
            'room_id' => $kamar->id, 'code' => 'OKSIGEN', 'name' => 'Oksigen sentral',
            'amount' => 25000,
            'effective_from' => '2026-07-01',
            'effective_until' => '2026-01-01',
        ]);
    }

    #[Test]
    public function total_harian_dihitung_bukan_disimpan(): void
    {
        $kamar = $this->kamar();

        $biaya = RoomDailyCharge::query()->create([
            'room_id' => $kamar->id, 'code' => 'LINEN', 'name' => 'Laundry linen',
            'amount' => 15000, 'quantity' => 2, 'effective_from' => '2026-01-01',
        ]);

        $this->assertSame('30000.00', $biaya->totalHarian());

        // Dikoreksi jumlahnya, totalnya ikut — kolom total yang dibekukan
        // akan salah di sini tanpa ada yang memberitahu.
        $biaya->update(['quantity' => 3]);

        $this->assertSame('45000.00', $biaya->fresh()->totalHarian());
    }

    #[Test]
    public function view_biaya_harian_diterbitkan_untuk_billing(): void
    {
        $kamar = $this->kamar();

        RoomDailyCharge::query()->create([
            'room_id' => $kamar->id, 'code' => 'ASKEP', 'name' => 'Asuhan Keperawatan',
            'amount' => 50000, 'quantity' => 1, 'effective_from' => '2026-01-01',
        ]);

        $baris = DB::table('inpatient.v_room_daily_charge')->first();

        $this->assertSame('ASKEP', $baris->code);
        $this->assertSame('101', $baris->room_number);
        $this->assertSame('50000.00', $baris->total);
    }

    private function kamar(): Room
    {
        return Room::query()->create([
            'room_number' => '101',
            'room_class' => 'kelas-1',
            'daily_rate' => 300000,
            'is_active' => true,
        ]);
    }
}
