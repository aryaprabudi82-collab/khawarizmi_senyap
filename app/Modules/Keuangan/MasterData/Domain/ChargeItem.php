<?php

namespace App\Modules\Keuangan\MasterData\Domain;

use Illuminate\Database\Eloquent\Model;

/**
 * Item yang bisa ditagihkan — satu kode global untuk seluruh rumah sakit.
 *
 * Menaungi tindakan, layanan penunjang, obat, BHP, alkes, akomodasi kamar,
 * visite, parkir, dan barang koperasi. Sebelum ini, "apa saja yang bisa
 * ditagihkan" tersebar di enam tabel pada lima konteks dan tidak ada satu
 * tempat pun yang bisa menjawabnya.
 *
 * IA MENUNJUK KE TARIFNYA, TIDAK MENYIMPANNYA. Tarif layanan klinis tetap
 * di `catalog.tariffs` yang sudah bitemporal dan sudah diresolusi per
 * tanggal transaksi; harga obat tetap di pharmacy. Yang dibawa CDM adalah
 * kode globalnya dan PEMETAANNYA KE AKUN — bagian yang benar-benar belum
 * ada di mana pun, dan yang membuat pendapatan tidak bisa dijurnalkan.
 */
class ChargeItem extends Model
{
    public const GOL_TINDAKAN = 'tindakan';

    public const GOL_PENUNJANG = 'penunjang';

    public const GOL_OBAT = 'obat';

    public const GOL_BHP = 'bhp';

    public const GOL_ALKES = 'alkes';

    public const GOL_AKOMODASI = 'akomodasi';

    public const GOL_VISITE = 'visite';

    public const GOL_ADMINISTRASI = 'administrasi';

    public const GOL_PAKET = 'paket';

    public const GOL_LAIN = 'lain';

    /** Golongan yang menghasilkan beban pokok, jadi wajib punya akun HPP. */
    public const BERPERSEDIAAN = [self::GOL_OBAT, self::GOL_BHP, self::GOL_ALKES];

    /** Dimensi program PTN-BH — pemisah dana pelayanan/pendidikan/penelitian. */
    public const PROGRAM = ['pelayanan', 'pendidikan', 'penelitian'];

    protected $table = 'keuangan_master.charge_items';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'is_taxable' => 'boolean',
            'valid_from' => 'date',
            'valid_until' => 'date',
        ];
    }

    /**
     * Apakah item ini siap diaktifkan.
     *
     * Sengaja dibedakan dari `is_active`: yang ini menjawab "boleh
     * diaktifkan?", yang itu "sedang aktif?". Basis data menegakkan
     * aturannya lewat CHECK; method ini ada supaya layar bisa menjelaskan
     * APA yang kurang sebelum pengguna menekan tombol dan menerima galat
     * basis data yang tidak menolong siapa pun.
     */
    public function siapDiaktifkan(): bool
    {
        return $this->alasanBelumSiap() === null;
    }

    /** Alasan item belum boleh diaktifkan, atau null bila sudah siap. */
    public function alasanBelumSiap(): ?string
    {
        if ($this->revenue_account_id === null) {
            return 'Belum dipetakan ke akun pendapatan. Item yang menagih tanpa akun berarti '
                .'ada uang masuk yang tidak pernah sampai ke buku besar, dan selisihnya baru '
                .'ketahuan saat ada yang menutup buku.';
        }

        if (in_array($this->golongan, self::BERPERSEDIAAN, true) && $this->cogs_account_id === null) {
            return 'Barang berpersediaan wajib punya akun beban pokok. Tanpa itu, HPP-nya tidak '
                .'bisa dijurnalkan dan nilai persediaan di buku besar akan terus melenceng dari gudang.';
        }

        return null;
    }

    public function berlakuPada(string $tanggal): bool
    {
        return $this->valid_from->toDateString() <= $tanggal
            && ($this->valid_until === null || $this->valid_until->toDateString() >= $tanggal);
    }
}
