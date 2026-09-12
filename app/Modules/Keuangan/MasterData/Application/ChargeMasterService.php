<?php

namespace App\Modules\Keuangan\MasterData\Application;

use App\Modules\Keuangan\MasterData\Domain\ChargeItem;
use App\Modules\Keuangan\Shared\Domain\KeuanganException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Pengelola Charge Description Master — Modul A.
 *
 * TIGA ATURAN YANG DITEGAKKAN DI SINI, dan ketiganya punya akibat nyata
 * bila dilanggar:
 *
 * 1. ITEM TANPA PEMETAAN AKUN TIDAK BISA DIAKTIFKAN. Item yang menagih
 *    tanpa akun berarti ada uang masuk yang tidak pernah sampai ke buku
 *    besar. Ditegakkan CHECK di basis data juga — di sini hanya supaya
 *    pesannya menolong, bukan galat SQL mentah.
 *
 * 2. KODE TIDAK PERNAH DIHAPUS, HANYA DI-EXPIRE. Kode yang dipakai ulang
 *    membuat tagihan tahun lalu menunjuk barang yang sama sekali lain —
 *    dan tidak ada cara mengetahuinya selain membandingkan tanggal.
 *
 * 3. SATU SUMBER, SATU ITEM BERJALAN. Dua item CDM yang menunjuk baris
 *    tarif yang sama akan menagih hal yang sama dua kali dengan kode
 *    berbeda, dan rekonsiliasinya mustahil.
 */
class ChargeMasterService
{
    /**
     * Mendaftarkan item baru. Lahir NONAKTIF — pengaktifannya terpisah,
     * dan itu disengaja: mendaftar dan mengizinkan menagih adalah dua
     * keputusan yang berbeda, sering oleh orang yang berbeda.
     *
     * @param  array<string, mixed>  $data
     *
     * @throws KeuanganException
     */
    public function daftarkan(array $data): ChargeItem
    {
        $kode = strtoupper(trim((string) ($data['code'] ?? '')));

        if ($kode === '') {
            throw new KeuanganException('Kode item wajib diisi — ia yang dipakai seluruh sistem menyebut item ini.');
        }

        if (ChargeItem::query()->where('code', $kode)->exists()) {
            throw new KeuanganException(
                "Kode item '{$kode}' sudah dipakai. Kode tidak boleh dipakai ulang: tagihan lama "
                .'yang memakainya akan menunjuk barang yang sama sekali lain.'
            );
        }

        $this->pastikanSumberBelumTertaut(
            (string) $data['source_context'],
            isset($data['source_id']) ? (int) $data['source_id'] : null
        );

        return ChargeItem::query()->create([
            'code' => $kode,
            'name' => $data['name'],
            'golongan' => $data['golongan'],
            'source_context' => $data['source_context'],
            'source_id' => $data['source_id'] ?? null,
            'revenue_account_id' => $data['revenue_account_id'] ?? null,
            'cogs_account_id' => $data['cogs_account_id'] ?? null,
            'discount_account_id' => $data['discount_account_id'] ?? null,
            'default_program' => $data['default_program'] ?? null,
            'default_unit_id' => $data['default_unit_id'] ?? null,
            'is_taxable' => $data['is_taxable'] ?? false,
            'tax_code' => $data['tax_code'] ?? null,
            'is_active' => false,
            'valid_from' => $data['valid_from'] ?? now()->toDateString(),
        ]);
    }

    /**
     * Memetakan item ke akun-akun COA.
     *
     * @throws KeuanganException
     */
    public function petakanAkun(
        ChargeItem $item,
        int $revenueAccountId,
        ?int $cogsAccountId = null,
        ?int $discountAccountId = null,
    ): ChargeItem {
        foreach (array_filter([$revenueAccountId, $cogsAccountId, $discountAccountId]) as $akun) {
            $this->pastikanAkunAda((int) $akun);
        }

        $item->update([
            'revenue_account_id' => $revenueAccountId,
            'cogs_account_id' => $cogsAccountId,
            'discount_account_id' => $discountAccountId,
        ]);

        return $item->refresh();
    }

    /**
     * Mengaktifkan item supaya boleh menagih.
     *
     * @throws KeuanganException
     */
    public function aktifkan(ChargeItem $item): ChargeItem
    {
        if ($alasan = $item->alasanBelumSiap()) {
            throw new KeuanganException("Item '{$item->code}' belum bisa diaktifkan. ".$alasan);
        }

        $item->update(['is_active' => true]);

        return $item->refresh();
    }

    /**
     * Meng-expire item — BUKAN menghapusnya.
     *
     * Tagihan lama tetap menunjuk item ini dan tetap harus bisa dibaca.
     * Menghapusnya membuat tagihan tahun lalu kehilangan keterangan
     * tentang apa yang sebenarnya ditagihkan.
     *
     * @throws KeuanganException
     */
    public function expire(ChargeItem $item, string $berlakuSampai): ChargeItem
    {
        if ($item->valid_until !== null) {
            throw new KeuanganException("Item '{$item->code}' sudah di-expire pada {$item->valid_until->format('d-m-Y')}.");
        }

        /*
         * YANG DITOLAK HANYA TANGGAL SEBELUM ITEMNYA MULAI BERLAKU —
         * bukan tanggal yang sudah lewat.
         *
         * Percobaan pertama membandingkannya dengan HARI INI, dan itu
         * salah: meng-expire terhitung mundur adalah kebutuhan nyata.
         * Tarif yang ternyata sudah tidak berlaku sejak awal bulan harus
         * bisa ditutup pada tanggal itu, bukan pada tanggal seseorang
         * kebetulan menyadarinya — kalau tidak, tagihan sepanjang bulan
         * itu memakai tarif yang seharusnya sudah mati.
         *
         * Yang mustahil cuma satu: item berakhir sebelum ia mulai.
         */
        if ($berlakuSampai < $item->valid_from->toDateString()) {
            throw new KeuanganException(
                "Tanggal berakhir ({$berlakuSampai}) mendahului tanggal mulai berlaku "
                ."({$item->valid_from->toDateString()}). Item tidak bisa berakhir sebelum ia mulai."
            );
        }

        /*
         * `is_active` TIDAK DIUBAH, dan itu koreksi atas cacat nyata.
         *
         * Percobaan pertama menyetelnya false bersamaan dengan expire, dan
         * akibatnya item HILANG DARI SELURUH TANGGAL — termasuk masa ia
         * masih sah berlaku. Rekap tarif bulan lalu jadi kehilangan item
         * yang waktu itu benar-benar dipakai menagih, dan tidak ada satu
         * pun tanda bahwa ada yang hilang.
         *
         * Kedua kolom ini menjawab pertanyaan yang berbeda:
         *
         *   is_active   : boleh dipakai MENAGIH sekarang?
         *   valid_until : SAMPAI KAPAN ia pernah berlaku?
         *
         * Yang menentukan apakah sebuah item berlaku pada satu tanggal
         * adalah rentang tanggalnya, bukan penanda aktifnya. `is_active`
         * dipakai untuk menonaktifkan sementara — item yang salah harga
         * dan perlu ditahan sampai diperbaiki, tanpa mengubah riwayatnya.
         */
        $item->update(['valid_until' => $berlakuSampai]);

        return $item->refresh();
    }

    /**
     * Item yang berlaku pada satu tanggal, untuk golongan tertentu.
     *
     * @return Collection<int, ChargeItem>
     */
    public function berlakuPada(string $tanggal, ?string $golongan = null): Collection
    {
        return ChargeItem::query()
            ->where('is_active', true)
            ->where('valid_from', '<=', $tanggal)
            ->where(fn ($q) => $q->whereNull('valid_until')->orWhere('valid_until', '>=', $tanggal))
            ->when($golongan, fn ($q) => $q->where('golongan', $golongan))
            ->orderBy('code')
            ->get();
    }

    /**
     * Item yang BELUM dipetakan ke akun — inilah daftar pekerjaan bagian
     * keuangan, dan alasan utama layar CDM ada.
     *
     * @return Collection<int, ChargeItem>
     */
    public function belumDipetakan(): Collection
    {
        return ChargeItem::query()
            ->whereNull('valid_until')
            ->where(fn ($q) => $q->whereNull('revenue_account_id')
                ->orWhere(fn ($q2) => $q2->whereIn('golongan', ChargeItem::BERPERSEDIAAN)
                    ->whereNull('cogs_account_id')))
            ->orderBy('golongan')
            ->orderBy('code')
            ->get();
    }

    /**
     * Cakupan pemetaan — dipakai gate Wave 1 dan layar kesiapan.
     *
     * @return array{total: int, terpetakan: int, persen: float|null, belum: int}
     */
    public function cakupanPemetaan(): array
    {
        $total = ChargeItem::query()->whereNull('valid_until')->count();
        $belum = $this->belumDipetakan()->count();

        return [
            'total' => $total,
            'terpetakan' => $total - $belum,
            /*
             * Nol item berarti cakupannya TIDAK ADA, bukan 100%.
             * Melaporkan katalog kosong sebagai "cakupan penuh" adalah
             * jenis kabar baik yang menghentikan pertanyaan berikutnya.
             */
            'persen' => $total > 0 ? round(($total - $belum) * 100 / $total, 1) : null,
            'belum' => $belum,
        ];
    }

    /** @throws KeuanganException */
    private function pastikanSumberBelumTertaut(string $context, ?int $sourceId): void
    {
        if ($sourceId === null) {
            return;
        }

        $ada = ChargeItem::query()
            ->where('source_context', $context)
            ->where('source_id', $sourceId)
            ->whereNull('valid_until')
            ->first();

        if ($ada !== null) {
            throw new KeuanganException(
                "Sumber {$context}#{$sourceId} sudah tertaut ke item '{$ada->code}'. Dua item CDM "
                .'yang menunjuk baris tarif yang sama akan menagih hal yang sama dua kali dengan '
                .'kode berbeda, dan rekonsiliasinya mustahil.'
            );
        }
    }

    /**
     * Akun dibaca lewat KONTRAK TERBITAN finance, bukan tabelnya.
     *
     * PERCOBAAN PERTAMA MEMBACA `finance.chart_of_accounts` LANGSUNG,
     * dengan komentar "sementara, akan diganti kontrak view saat COA
     * dipindahkan". Uji batas konteks menolaknya — dan penolakan itu
     * benar: yang berlaku bukan maksud yang tertulis di komentar,
     * melainkan apa yang sungguh dikerjakan kodenya. Komentar "sementara"
     * yang tidak ada tenggatnya adalah cara paling sopan membuat
     * pelanggaran jadi permanen.
     *
     * `v_account` sengaja TIDAK membawa saldo: saldo adalah hasil
     * hitungan atas jurnal, bukan atribut akun, dan menerbitkannya akan
     * mengundang konteks lain menghitung saldo sendiri-sendiri.
     *
     * @throws KeuanganException
     */
    private function pastikanAkunAda(int $accountId): void
    {
        $akun = DB::table('finance.v_account')
            ->where('id', $accountId)
            ->first();

        if ($akun === null || ! $akun->is_active) {
            throw new KeuanganException("Akun #{$accountId} tidak ditemukan atau sudah tidak aktif.");
        }

        /*
         * AKUN RINGKASAN TIDAK BOLEH JADI TUJUAN PEMETAAN. Akun induk yang
         * hanya menjumlahkan anaknya, kalau dijurnalkan langsung, membuat
         * saldonya jadi campuran antara jumlah anaknya dan jurnalnya
         * sendiri — dan tidak ada cara memisahkan keduanya lagi.
         */
        if (! $akun->is_postable) {
            throw new KeuanganException(
                "Akun {$akun->code} ({$akun->name}) adalah akun ringkasan yang menjumlahkan akun di "
                .'bawahnya, jadi tidak boleh dijurnalkan langsung. Pilih salah satu akun anaknya.'
            );
        }
    }
}
