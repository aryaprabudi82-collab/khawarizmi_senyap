<?php

namespace App\Modules\Finance\Models;

use Illuminate\Database\Eloquent\Model;

class Account extends Model
{
    public const TYPE_KAS = 'kas';

    public const TYPE_PIUTANG = 'piutang';

    public const TYPE_PENDAPATAN = 'pendapatan';

    public const TYPE_BEBAN = 'beban';

    public const TYPE_UTANG = 'utang';

    // Ditambahkan domain K item E: bagan akun yang tidak mengenal aset dan
    // modal tidak bisa menampung neraca.
    public const TYPE_ASET = 'aset';

    public const TYPE_MODAL = 'modal';

    public const JENIS = [
        self::TYPE_KAS, self::TYPE_PIUTANG, self::TYPE_PENDAPATAN,
        self::TYPE_BEBAN, self::TYPE_UTANG, self::TYPE_ASET, self::TYPE_MODAL,
    ];

    /*
     * GOLONGAN LAPORAN, TERPISAH DARI JENIS AKUN — dan pemisahan ini lahir
     * dari cacat yang ditemukan saat menelusuri layar keuangan.
     *
     * Ketujuh jenis di atas BERCAMPUR DUA TINGKATAN. `aset`, `utang`,
     * `modal`, `pendapatan`, dan `beban` adalah golongan akuntansi yang
     * sah. Tapi `kas` dan `piutang` sesungguhnya SUB-GOLONGAN ASET yang
     * terlanjur disejajarkan dengan induknya.
     *
     * Akibatnya nyata: neraca yang dikelompokkan menurut `type` akan
     * kehilangan seluruh kas dan piutang rumah sakit — dua pos terbesar
     * pada neraca rumah sakit mana pun — karena keduanya tidak pernah
     * masuk kelompok aset. Neracanya tetap tersusun rapi dan tetap
     * seimbang, hanya salah.
     *
     * KEDUANYA TIDAK DIHAPUS, dan itu keputusan yang disengaja.
     * PostingService dan DepositService memakai jenis akun sebagai
     * PENUNJUK — `account(TYPE_KAS)` berarti "carikan akun kas". Menghapus
     * keduanya akan mematikan seluruh penjurnalan otomatis demi kerapian
     * daftar. Jadi jenisnya tetap, dan golongan laporannya ditambahkan
     * sebagai lapisan sendiri di sini.
     */

    public const GOL_ASET = 'aset';

    public const GOL_KEWAJIBAN = 'kewajiban';

    public const GOL_MODAL = 'modal';

    public const GOL_PENDAPATAN = 'pendapatan';

    public const GOL_BEBAN = 'beban';

    /** @var array<string, string> */
    public const GOLONGAN = [
        self::TYPE_KAS => self::GOL_ASET,
        self::TYPE_PIUTANG => self::GOL_ASET,
        self::TYPE_ASET => self::GOL_ASET,
        self::TYPE_UTANG => self::GOL_KEWAJIBAN,
        self::TYPE_MODAL => self::GOL_MODAL,
        self::TYPE_PENDAPATAN => self::GOL_PENDAPATAN,
        self::TYPE_BEBAN => self::GOL_BEBAN,
    ];

    /** @var array<string, string> */
    public const LABEL_GOLONGAN = [
        self::GOL_ASET => 'Aset',
        self::GOL_KEWAJIBAN => 'Kewajiban',
        self::GOL_MODAL => 'Modal',
        self::GOL_PENDAPATAN => 'Pendapatan',
        self::GOL_BEBAN => 'Beban',
    ];

    /** Golongan laporan sebuah jenis akun. */
    public static function golonganDari(string $type): string
    {
        return self::GOLONGAN[$type] ?? self::GOL_ASET;
    }

    public function golongan(): string
    {
        return self::golonganDari($this->type);
    }

    /**
     * Akun yang masuk NERACA (posisi keuangan), bukan laba-rugi.
     *
     * Aset, kewajiban, dan modal adalah KEADAAN pada satu tanggal;
     * pendapatan dan beban adalah ALIRAN sepanjang satu periode.
     * Mencampurnya menghasilkan dokumen yang tidak menjawab pertanyaan
     * mana pun dengan benar.
     */
    public function isNeraca(): bool
    {
        return in_array($this->golongan(), [self::GOL_ASET, self::GOL_KEWAJIBAN, self::GOL_MODAL], true);
    }

    /**
     * Akun yang saldonya BERTAMBAH oleh debit.
     *
     * Dipakai buku besar untuk menyajikan saldo sesuai arah normalnya.
     * Tanpa ini setiap akun kredit-normal akan tampil negatif dan
     * pembacanya menyimpulkan ada yang rusak.
     */
    public function isDebitNormal(): bool
    {
        return self::isDebitNormalUntuk($this->type);
    }

    public static function isDebitNormalUntuk(string $type): bool
    {
        return in_array(self::golonganDari($type), [self::GOL_ASET, self::GOL_BEBAN], true);
    }

    protected $table = 'finance.chart_of_accounts';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }
}
