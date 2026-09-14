<?php

namespace App\Modules\Keuangan\MasterData\Infrastructure\Resolvers;

use App\Modules\Keuangan\MasterData\Domain\ChargeItem;
use App\Modules\Keuangan\MasterData\Domain\TariffResolver;
use App\Modules\Keuangan\Shared\Domain\Money;
use Illuminate\Support\Facades\DB;

/**
 * Harga barang koperasi.
 *
 * BERDIMENSI TINGKAT HARGA, BUKAN PENJAMIN — dan pembedaan itu penting.
 * Penjualan koperasi tidak mengenal penjamin: pembelinya bisa pasien,
 * pengunjung, atau pegawai, dan yang membedakan harganya adalah TINGKAT
 * HARGA (umum / pegawai / mitra), bukan siapa yang menanggung.
 *
 * Menyeret dimensi penjamin ke sini akan memaksa tiap penjualan koperasi
 * mengarang sebuah penjamin — dan penjamin karangan itu lalu muncul di
 * laporan piutang penjamin sebagai baris yang tidak bisa ditagih ke
 * siapa pun.
 *
 * Retail masih kosong. Resolver ini ditulis sekarang supaya penautan CDM
 * lengkap sejak awal: saat produk pertama diisi, ia langsung punya kode
 * dan pemetaan akun, bukan menunggu sampai ada yang menyadari penjualan
 * pertamanya tidak masuk buku besar.
 */
class RetailTariffResolver implements TariffResolver
{
    /** Tingkat harga bawaan bila pemanggil tidak menyebutkannya. */
    private const TINGKAT_UMUM = 'UMUM';

    public function konteks(): string
    {
        return 'retail';
    }

    public function tarif(ChargeItem $item, string $tanggal, array $konteksPenagihan = []): ?Money
    {
        $tingkat = strtoupper((string) ($konteksPenagihan['price_tier'] ?? self::TINGKAT_UMUM));

        $baris = DB::table('retail.v_product_price')
            ->where('product_id', $item->source_id)
            ->where('price_tier_code', $tingkat)
            ->whereNotNull('price')
            ->first();

        if ($baris === null) {
            /*
             * Sengaja TIDAK jatuh ke tingkat umum saat tingkat yang diminta
             * belum berharga. Jatuh diam-diam berarti pegawai yang berhak
             * harga khusus ditagih harga umum — atau sebaliknya — dan
             * tidak ada yang tahu sampai ada yang mengeluh.
             */
            return null;
        }

        $jumlah = (int) ($konteksPenagihan['quantity'] ?? 1);

        if ($jumlah < 1) {
            return null;
        }

        return Money::tagihan((string) $baris->price)->kali($jumlah);
    }

    public function belumTertaut(): iterable
    {
        $baris = DB::select("
            SELECT DISTINCT p.product_id, p.product_code, p.product_name
              FROM retail.v_product_price p
             WHERE p.is_active = true
               AND NOT EXISTS (
                   SELECT 1 FROM keuangan_master.charge_items c
                    WHERE c.source_context = 'retail'
                      AND c.source_id = p.product_id
                      AND c.valid_until IS NULL
               )
             ORDER BY p.product_code
        ");

        foreach ($baris as $p) {
            yield [
                'source_id' => (int) $p->product_id,
                'code' => $p->product_code,
                'name' => $p->product_name,
                'golongan' => ChargeItem::GOL_LAIN,
            ];
        }
    }
}
