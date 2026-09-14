<?php

namespace App\Modules\Keuangan\MasterData\Infrastructure\Resolvers;

use App\Modules\Keuangan\MasterData\Domain\ChargeItem;
use App\Modules\Keuangan\MasterData\Domain\TariffResolver;
use App\Modules\Keuangan\Shared\Domain\Money;
use Illuminate\Support\Facades\DB;

/**
 * Tarif layanan klinis — tindakan, operasi, registrasi.
 *
 * Membaca `catalog.v_tariff`, BUKAN `catalog.tariffs`. Uji batas konteks
 * melarang pembacaan langsung, dan larangan itu benar: pembacaan langsung
 * berarti tiap perubahan kolom catalog memutus keuangan tanpa peringatan.
 *
 * TIGA DIMENSI yang menentukan tarif layanan, dan ketiganya wajib:
 * penjamin, kelas rawat, dan tanggal. Menghilangkan salah satunya membuat
 * pasien BPJS ditagih tarif umum, atau kunjungan tahun lalu dinilai dengan
 * tarif hari ini — dua-duanya salah tanpa ada galat yang terlihat.
 *
 * TARIF DIBACA SEBAGAI STRING, bukan float. Kolomnya `numeric(14,2)` di
 * PostgreSQL; membacanya sebagai float sudah cukup untuk membuat
 * 1.234.567,89 kehilangan sennya, dan Money memang menolak float
 * mentah-mentah.
 */
class CatalogTariffResolver implements TariffResolver
{
    /**
     * Kategori layanan catalog -> golongan CDM.
     *
     * Registrasi masuk `administrasi` dan bukan `tindakan`: ia ditagihkan
     * sekali per kunjungan, bukan per pelayanan, dan akun pendapatannya
     * pada praktiknya selalu berbeda.
     */
    private const GOLONGAN = [
        'tindakan' => ChargeItem::GOL_TINDAKAN,
        'operasi' => ChargeItem::GOL_TINDAKAN,
        'registrasi' => ChargeItem::GOL_ADMINISTRASI,
        'penunjang' => ChargeItem::GOL_PENUNJANG,
        'laboratorium' => ChargeItem::GOL_PENUNJANG,
        'radiologi' => ChargeItem::GOL_PENUNJANG,
        'visite' => ChargeItem::GOL_VISITE,
        'paket' => ChargeItem::GOL_PAKET,
    ];

    public function konteks(): string
    {
        return 'catalog';
    }

    public function tarif(ChargeItem $item, string $tanggal, array $konteksPenagihan = []): ?Money
    {
        $payerId = $konteksPenagihan['payer_id'] ?? null;

        if ($payerId === null) {
            /*
             * Tanpa penjamin tarifnya TIDAK BISA ditentukan, dan
             * mengembalikan tarif umum sebagai "bawaan" adalah cara paling
             * halus kehilangan pendapatan: pasien asuransi ditagih tarif
             * umum, selisihnya ditanggung rumah sakit, dan tidak ada baris
             * galat di mana pun.
             */
            return null;
        }

        $kelas = (string) ($konteksPenagihan['care_class'] ?? '-');

        $baris = DB::table('catalog.v_tariff')
            ->where('service_id', $item->source_id)
            ->where('payer_id', (int) $payerId)
            ->where('care_class', $kelas)
            ->whereRaw('valid_from <= ?::date', [$tanggal])
            ->where(fn ($q) => $q->whereNull('valid_until')
                ->orWhereRaw('valid_until >= ?::date', [$tanggal]))
            ->orderByDesc('valid_from')
            ->first();

        if ($baris === null) {
            return null;
        }

        $ulangan = (bool) ($konteksPenagihan['returning_patient'] ?? false);

        $nominal = ($ulangan && $baris->amount_returning !== null)
            ? $baris->amount_returning
            : $baris->amount;

        return Money::tagihan((string) $nominal);
    }

    public function belumTertaut(): iterable
    {
        $baris = DB::select("
            SELECT s.id, s.code, s.name, s.category
              FROM catalog.v_service s
             WHERE s.is_active = true
               AND NOT EXISTS (
                   SELECT 1 FROM keuangan_master.charge_items c
                    WHERE c.source_context = 'catalog'
                      AND c.source_id = s.id
                      AND c.valid_until IS NULL
               )
             ORDER BY s.code
        ");

        foreach ($baris as $s) {
            yield [
                'source_id' => (int) $s->id,
                'code' => $s->code,
                'name' => $s->name,
                'golongan' => self::GOLONGAN[$s->category] ?? ChargeItem::GOL_LAIN,
            ];
        }
    }
}
