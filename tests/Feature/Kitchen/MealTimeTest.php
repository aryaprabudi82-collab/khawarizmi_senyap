<?php

namespace Tests\Feature\Kitchen;

use App\Modules\Kitchen\Models\MealTime;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Slot waktu makan pasien (Khanza `jam_diet_pasien`, domain U).
 *
 * Bentuk cacatnya sekeluarga dengan `biaya_harian` dan `set_pjlab`: sesuatu
 * yang akan bertambah dikunci ke tempat yang tidak bisa bertambah. Di sini
 * dua belas slot dikunci di dalam TIPE KOLOM — enum('Pagi','Pagi2','Pagi3',
 * 'Siang',...,'Malam3') — jadi menambah yang ketiga belas berarti mengubah
 * tipe kolom, yaitu mengunci tabel. Dan "Pagi2" bukan nama yang berarti apa
 * pun bagi petugas gizi; ia nomor urut yang bocor ke dalam data.
 */
class MealTimeTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function slot_bisa_ditambah_dan_dinamai_tanpa_mengubah_skema(): void
    {
        foreach ([['PAGI', 'Makan pagi'], ['SNACK-PAGI', 'Snack pagi'], ['SIANG', 'Makan siang']] as $i => [$kode, $nama]) {
            MealTime::query()->create([
                'code' => $kode, 'name' => $nama, 'position' => $i + 1, 'is_active' => true,
            ]);
        }

        $this->assertSame(3, MealTime::query()->count());

        // Namanya kalimat yang dipakai petugas, bukan nomor urut.
        $this->assertSame('Snack pagi', MealTime::query()->where('code', 'SNACK-PAGI')->first()->name);
    }

    #[Test]
    public function slot_tanpa_jam_terbedakan_dari_slot_tengah_malam(): void
    {
        $belum = MealTime::query()->create(['code' => 'SORE', 'name' => 'Snack sore', 'is_active' => true]);
        $malam = MealTime::query()->create([
            'code' => 'MALAM', 'name' => 'Makan malam', 'serve_at' => '00:00', 'is_active' => true,
        ]);

        /*
         * Kalau jamnya dipaksa terisi, orang akan mengetik 00:00 untuk slot
         * yang jamnya belum ditetapkan — dan dapur menjadwalkan pengantaran
         * tengah malam untuk sesuatu yang belum disepakati waktunya.
         */
        $this->assertTrue($belum->jamBelumDitetapkan());
        $this->assertFalse($malam->jamBelumDitetapkan());
    }

    #[Test]
    public function master_waktu_makan_lahir_kosong(): void
    {
        // Jam makan RSP UI adalah keputusan instalasi gizi, terikat jadwal
        // produksi dapur dan jadwal obat. Menebaknya berarti menerbitkan
        // jadwal makan resmi yang tidak pernah disepakati siapa pun, lalu
        // memakainya menjadwalkan pengantaran ke bangsal.
        $this->assertSame(0, MealTime::query()->count());
    }

    #[Test]
    public function slot_diterbitkan_supaya_order_diet_bisa_menunjuknya(): void
    {
        MealTime::query()->create([
            'code' => 'PAGI', 'name' => 'Makan pagi', 'serve_at' => '06:30',
            'position' => 1, 'is_active' => true,
        ]);

        // Rumahnya di dapur karena yang menetapkan jam makan adalah yang
        // harus memasaknya tepat waktu; order diet (inpatient) menunjuk ke
        // sini lewat kontrak ini, bukan sebaliknya.
        $baris = DB::table('kitchen.v_meal_time')->first();

        $this->assertSame('PAGI', $baris->code);
        $this->assertSame('Makan pagi', $baris->name);
    }
}
