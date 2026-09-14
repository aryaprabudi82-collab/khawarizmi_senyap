<?php

namespace App\Modules\Keuangan\MasterData\Infrastructure\Resolvers;

use App\Modules\Keuangan\MasterData\Domain\ChargeItem;
use App\Modules\Keuangan\MasterData\Domain\TariffResolver;
use App\Modules\Keuangan\Shared\Domain\Money;
use Illuminate\Support\Facades\DB;

/**
 * Akomodasi kamar rawat inap.
 *
 * MEMBACA `v_room_rate`, DAN PEMILIHAN VIEW-NYA BUKAN HAL SEPELE. Inpatient
 * menerbitkan tiga view berkamar, dan dua di antaranya akan menghasilkan
 * tagihan yang salah tanpa melempar galat apa pun:
 *
 *   v_room_daily_charge — biaya harian TAMBAHAN (oksigen, laundry).
 *                         Memakainya berarti menagih biaya oksigen sebagai
 *                         harga kamar.
 *   v_room_class_rate   — RATA-RATA per kelas. Dua kamar VIP bertarif beda
 *                         akan ditagih di angka tengah yang tidak pernah
 *                         diputuskan siapa pun, dan totalnya tetap terlihat
 *                         wajar sehingga tidak ada yang memeriksanya.
 *
 * TARIF KAMAR TIDAK DIPINDAH KE CDM. `daily_rate` melekat pada kamar, dan
 * kamar dipakai papan ketersediaan tempat tidur, lama rawat, dan pemindahan
 * kamar — seluruhnya di luar keuangan.
 *
 * BELUM BERPERIODE — dan ini keterbatasan yang saya sengaja tidak sembunyikan.
 * `rooms.daily_rate` tidak punya valid_from/valid_until, jadi kenaikan tarif
 * hari ini ikut mengubah nilai rawat inap bulan lalu. Parameter `$tanggal`
 * diterima dan sengaja tidak dipakai supaya kontraknya sudah benar saat
 * tabel tarif kamar berperiode dibuat. Lihat OPEN-QUESTIONS Q14.
 */
class InpatientTariffResolver implements TariffResolver
{
    public function konteks(): string
    {
        return 'inpatient';
    }

    public function tarif(ChargeItem $item, string $tanggal, array $konteksPenagihan = []): ?Money
    {
        $baris = DB::table('inpatient.v_room_rate')
            ->where('room_id', $item->source_id)
            ->first();

        if ($baris === null || $baris->daily_rate === null) {
            return null;
        }

        $tarifHarian = Money::tagihan((string) $baris->daily_rate);

        /*
         * Akomodasi ditagih PER HARI RAWAT. Pengali dipisahkan dari
         * tarifnya supaya penggandaan terjadi pada bilangan bulat sen —
         * mengalikan desimal lebih dulu lalu membulatkan di akhir membuat
         * rawat 13 hari meleset dari 13 kali tarif satu hari, dan
         * selisihnya baru ketahuan saat pasien menanyakan rinciannya.
         */
        $hari = (int) ($konteksPenagihan['quantity'] ?? 1);

        if ($hari < 1) {
            return null;
        }

        return $tarifHarian->kali($hari);
    }

    public function belumTertaut(): iterable
    {
        $baris = DB::select("
            SELECT r.room_id, r.room_number, r.room_class, r.accommodation_name
              FROM inpatient.v_room_rate r
             WHERE r.is_active = true
               AND NOT EXISTS (
                   SELECT 1 FROM keuangan_master.charge_items c
                    WHERE c.source_context = 'inpatient'
                      AND c.source_id = r.room_id
                      AND c.valid_until IS NULL
               )
             ORDER BY r.room_number
        ");

        foreach ($baris as $r) {
            yield [
                /*
                 * Kode memakai NOMOR KAMAR, bukan kelasnya. Dua kamar VIP
                 * bisa bertarif berbeda — kamar sudut yang lebih luas, kamar
                 * dekat nurse station. Kode per kelas akan memaksa keduanya
                 * berbagi satu item CDM, dan begitu tarifnya berbeda salah
                 * satunya pasti ditagih salah.
                 */
                'source_id' => (int) $r->room_id,
                'code' => $r->room_number,
                'name' => $r->accommodation_name,
                'golongan' => ChargeItem::GOL_AKOMODASI,
            ];
        }
    }
}
