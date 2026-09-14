<?php

namespace App\Modules\Keuangan\MasterData\Domain;

use App\Modules\Keuangan\Shared\Domain\Money;

/**
 * Kontrak satu sumber tarif — Modul A, penautan gate Wave 1.
 *
 * MENGAPA KONTRAK, BUKAN SATU TABEL TARIF BARU.
 *
 * Tarif rumah sakit tidak berbentuk sama. Tarif tindakan berdimensi
 * penjamin dan kelas rawat; harga obat adalah harga dasar dikali markup
 * per penjamin; tarif kamar melekat pada kamarnya; tarif parkir dihitung
 * dari durasi. Memaksakan semuanya ke satu tabel berarti tabel itu punya
 * belasan kolom yang kebanyakan NULL, dan tiap pembacanya harus tahu
 * kolom mana yang berlaku untuk golongan mana — pengetahuan yang lalu
 * tersebar lagi, persis masalah yang hendak diselesaikan CDM.
 *
 * Maka CDM MENUNJUK, tidak menyimpan. Yang diseragamkan bukan bentuk
 * tarifnya melainkan CARA MENANYAKANNYA: satu pertanyaan, satu jawaban
 * bertipe Money, atau null bila tidak ada tarif yang berlaku.
 *
 * NULL BERBEDA DARI NOL, dan pembedaan itu yang paling menentukan. Nol
 * berarti "item ini memang gratis"; null berarti "tidak ada tarif yang
 * berlaku untuk kombinasi ini". Menyamakan keduanya membuat tagihan
 * diterbitkan senilai nol saat sebenarnya tarifnya belum diisi — dan
 * tidak ada yang tahu sampai ada yang membandingkan pendapatan dengan
 * jumlah kunjungan.
 */
interface TariffResolver
{
    /**
     * Konteks sumber yang dilayani resolver ini — sama persis dengan
     * `charge_items.source_context`.
     */
    public function konteks(): string;

    /**
     * Tarif yang berlaku untuk satu item CDM.
     *
     * @param  ChargeItem  $item  item CDM yang ditanyakan; `source_id`-nya
     *                            menunjuk baris di tabel sumber
     * @param  string  $tanggal  tanggal transaksi (Y-m-d), BUKAN hari ini —
     *                           kunjungan lama dinilai dengan tarif yang
     *                           berlaku saat itu
     * @param  array<string, mixed>  $konteksPenagihan  dimensi yang dibutuhkan
     *                                                  sumber: `payer_id`,
     *                                                  `care_class`,
     *                                                  `returning_patient`,
     *                                                  `quantity`, `minutes`
     * @return Money|null null bila tidak ada tarif yang berlaku — bukan nol
     */
    public function tarif(ChargeItem $item, string $tanggal, array $konteksPenagihan = []): ?Money;

    /**
     * Baris sumber yang BELUM punya item CDM berjalan.
     *
     * Dipakai penaut untuk menyapu, dan dipakai layar kesiapan untuk
     * menjawab "berapa banyak yang bisa ditagih tapi belum bisa
     * dijurnalkan".
     *
     * @return iterable<int, array{source_id: int, code: string, name: string, golongan: string}>
     */
    public function belumTertaut(): iterable;
}
