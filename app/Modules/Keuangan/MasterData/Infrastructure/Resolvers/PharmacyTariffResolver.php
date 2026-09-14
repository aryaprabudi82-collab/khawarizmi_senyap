<?php

namespace App\Modules\Keuangan\MasterData\Infrastructure\Resolvers;

use App\Modules\Keuangan\MasterData\Domain\ChargeItem;
use App\Modules\Keuangan\MasterData\Domain\TariffResolver;
use App\Modules\Keuangan\Shared\Domain\Money;
use Illuminate\Support\Facades\DB;

/**
 * Harga jual obat, BHP, dan alkes.
 *
 * HARGA JUAL = HARGA DASAR x (100% + MARKUP PENJAMIN). Markup berperiode,
 * berdimensi penjamin, dan opsional berdimensi kelas rawat — baris
 * berkelas mengalahkan baris "semua kelas", karena markup ICU yang sengaja
 * ditetapkan berbeda tidak boleh tertimpa baris umum yang kebetulan dibuat
 * belakangan.
 *
 * MARKUP YANG BELUM DITETAPKAN BUKAN NOL. Menagih dengan markup nol
 * berarti menjual obat seharga modal tanpa ada yang memutuskan begitu, dan
 * marjin farmasi yang hilang tidak muncul sebagai galat di mana pun — ia
 * hanya muncul sebagai laporan yang terlihat wajar dengan angka yang
 * terlalu kecil. Maka: null.
 *
 * PEMBULATAN DITUNDA. Perkalian markup menghasilkan nilai berskala 4;
 * pembulatan ke rupiah dilakukan SEKALI di akhir, setelah dikalikan
 * jumlah. Membulatkan harga satuan lebih dulu membuat resep 30 tablet
 * meleset sampai 30 sen dari hitung ulang mana pun.
 */
class PharmacyTariffResolver implements TariffResolver
{
    /**
     * Kategori farmasi -> golongan CDM.
     *
     * Ketiganya BERPERSEDIAAN, jadi ketiganya wajib punya akun beban
     * pokok. Yang membedakan adalah akun mana — persediaan obat,
     * persediaan BHP, persediaan alkes — dan menyeragamkannya membuat
     * nilai persediaan di GL terus melenceng dari gudang.
     */
    private const GOLONGAN = [
        'obat' => ChargeItem::GOL_OBAT,
        'bhp' => ChargeItem::GOL_BHP,
        'alkes' => ChargeItem::GOL_ALKES,
    ];

    public function konteks(): string
    {
        return 'pharmacy';
    }

    public function tarif(ChargeItem $item, string $tanggal, array $konteksPenagihan = []): ?Money
    {
        $obat = DB::table('pharmacy.v_drug_price')
            ->where('drug_id', $item->source_id)
            ->first();

        if ($obat === null) {
            return null;
        }

        $dasar = Money::tagihan((string) $obat->base_price);

        $payerId = $konteksPenagihan['payer_id'] ?? null;

        if ($payerId === null) {
            return null;
        }

        $markup = $this->markupBerlaku(
            (int) $payerId,
            $konteksPenagihan['care_class'] ?? null,
            $tanggal
        );

        if ($markup === null) {
            return null;
        }

        // Harga satuan = dasar + (dasar x markup%), keduanya pada skala alokasi.
        $satuan = $dasar->bulatkanKe(Money::SKALA_ALOKASI)->tambah($dasar->persen($markup));

        $jumlah = (int) ($konteksPenagihan['quantity'] ?? 1);

        if ($jumlah < 1) {
            return null;
        }

        return $satuan->kali($jumlah)->sebagaiTagihan();
    }

    /**
     * Markup yang berlaku, dalam persen sebagai string.
     *
     * Aturan resolusinya sama persis dengan `DrugMarkup::berlaku()` di
     * konteks pharmacy — tetapi dibaca lewat kontrak terbitan, karena uji
     * batas konteks melarang keuangan mengimpor model konteks lain.
     */
    private function markupBerlaku(int $payerId, ?string $kelas, string $tanggal): ?string
    {
        $baris = DB::table('pharmacy.v_drug_markup')
            ->where('payer_id', $payerId)
            ->whereRaw('effective_from <= ?::date', [$tanggal])
            ->where(fn ($q) => $q->whereNull('effective_until')
                ->orWhereRaw('effective_until >= ?::date', [$tanggal]))
            ->where(fn ($q) => $q->whereNull('room_class')
                ->when($kelas !== null, fn ($w) => $w->orWhere('room_class', $kelas)))
            ->orderByRaw('room_class IS NULL')   // yang berkelas lebih dulu
            ->orderByDesc('effective_from')
            ->first();

        return $baris === null ? null : (string) $baris->markup_percent;
    }

    public function belumTertaut(): iterable
    {
        $baris = DB::select("
            SELECT d.drug_id, d.drug_code, d.drug_name, d.category
              FROM pharmacy.v_drug_price d
             WHERE d.is_active = true
               AND NOT EXISTS (
                   SELECT 1 FROM keuangan_master.charge_items c
                    WHERE c.source_context = 'pharmacy'
                      AND c.source_id = d.drug_id
                      AND c.valid_until IS NULL
               )
             ORDER BY d.drug_code
        ");

        foreach ($baris as $d) {
            yield [
                'source_id' => (int) $d->drug_id,
                'code' => $d->drug_code,
                'name' => $d->drug_name,
                /*
                 * GOLONGAN DIAMBIL DARI KATEGORINYA, tidak diseragamkan
                 * jadi "obat". Golongan menentukan akun beban pokok mana
                 * yang wajib, dan menggolongkan kasa steril sebagai obat
                 * membuat HPP-nya jatuh ke akun persediaan obat — nilai
                 * persediaan farmasi di GL lalu terus melenceng dari
                 * gudang, dan penyebabnya tidak terlihat di laporan mana
                 * pun. `pharmacy.drugs.category` sudah dibatasi CHECK ke
                 * obat/bhp/alkes, persis golongan berpersediaan CDM.
                 */
                'golongan' => self::GOLONGAN[$d->category] ?? ChargeItem::GOL_OBAT,
            ];
        }
    }
}
