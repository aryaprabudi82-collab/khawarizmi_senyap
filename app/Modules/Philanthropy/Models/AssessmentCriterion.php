<?php

namespace App\Modules\Philanthropy\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Satu pilihan pada satu kategori asesmen kelayakan penerima dana kesehatan.
 *
 * ENAM BELAS DAFTAR KHANZA JADI SATU TABEL BERKATEGORI. Kelima belas tabel
 * `zis_keterangan_*` identik kolom per kolom (kode + keterangan), dan yang
 * keenam belas — kepemilikan rumah — TIDAK PUNYA TABEL sama sekali di
 * Khanza meski menunya ada. Pola yang sama dipakai IcraRiskItem di domain R.
 *
 * LAHIR NYARIS KOSONG. Batas penghasilan, ukuran rumah yang dianggap layak,
 * jenis dinding yang dianggap tidak layak: semuanya penilaian amil RSP UI.
 * Menebaknya berarti menerbitkan kriteria kemiskinan resmi yang tidak pernah
 * disepakati siapa pun, lalu memakainya menolak orang.
 *
 * KECUALI ASNAF. Delapan golongannya ditetapkan Al-Qur'an surah At-Taubah
 * ayat 60 dan tidak berubah — daftar yang ditetapkan di luar rumah sakit
 * boleh disalin, diskresi rumah sakit tidak boleh ditebak.
 */
class AssessmentCriterion extends Model
{
    public const KATEGORI_PENGELUARAN = 'pengeluaran';

    public const KATEGORI_PENGHASILAN = 'penghasilan';

    public const KATEGORI_UKURAN_RUMAH = 'ukuran-rumah';

    public const KATEGORI_DINDING_RUMAH = 'dinding-rumah';

    public const KATEGORI_LANTAI_RUMAH = 'lantai-rumah';

    public const KATEGORI_ATAP_RUMAH = 'atap-rumah';

    public const KATEGORI_KEPEMILIKAN_RUMAH = 'kepemilikan-rumah';

    public const KATEGORI_KAMAR_MANDI = 'kamar-mandi';

    public const KATEGORI_DAPUR = 'dapur';

    public const KATEGORI_KURSI = 'kursi';

    public const KATEGORI_PHBS = 'phbs';

    public const KATEGORI_ELEKTRONIK = 'elektronik';

    public const KATEGORI_TERNAK = 'ternak';

    public const KATEGORI_SIMPANAN = 'simpanan';

    public const KATEGORI_ASNAF = 'asnaf';

    public const KATEGORI_PATOLOGIS = 'patologis';

    /**
     * Urutan daftar ini adalah urutan wawancara di rumah calon penerima:
     * keadaan ekonomi dulu, lalu keadaan rumah dari luar ke dalam, lalu
     * harta yang bergerak, baru golongan dan kondisi kesehatannya.
     */
    public const KATEGORI = [
        self::KATEGORI_PENGELUARAN,
        self::KATEGORI_PENGHASILAN,
        self::KATEGORI_UKURAN_RUMAH,
        self::KATEGORI_DINDING_RUMAH,
        self::KATEGORI_LANTAI_RUMAH,
        self::KATEGORI_ATAP_RUMAH,
        self::KATEGORI_KEPEMILIKAN_RUMAH,
        self::KATEGORI_KAMAR_MANDI,
        self::KATEGORI_DAPUR,
        self::KATEGORI_KURSI,
        self::KATEGORI_PHBS,
        self::KATEGORI_ELEKTRONIK,
        self::KATEGORI_TERNAK,
        self::KATEGORI_SIMPANAN,
        self::KATEGORI_ASNAF,
        self::KATEGORI_PATOLOGIS,
    ];

    public const LABEL_KATEGORI = [
        self::KATEGORI_PENGELUARAN => 'Pengeluaran rumah tangga',
        self::KATEGORI_PENGHASILAN => 'Penghasilan',
        self::KATEGORI_UKURAN_RUMAH => 'Ukuran rumah',
        self::KATEGORI_DINDING_RUMAH => 'Dinding rumah',
        self::KATEGORI_LANTAI_RUMAH => 'Lantai rumah',
        self::KATEGORI_ATAP_RUMAH => 'Atap rumah',
        self::KATEGORI_KEPEMILIKAN_RUMAH => 'Status kepemilikan rumah',
        self::KATEGORI_KAMAR_MANDI => 'Kamar mandi',
        self::KATEGORI_DAPUR => 'Dapur',
        self::KATEGORI_KURSI => 'Kursi/perabot',
        self::KATEGORI_PHBS => 'Perilaku hidup bersih & sehat',
        self::KATEGORI_ELEKTRONIK => 'Barang elektronik',
        self::KATEGORI_TERNAK => 'Ternak',
        self::KATEGORI_SIMPANAN => 'Jenis simpanan',
        self::KATEGORI_ASNAF => 'Golongan asnaf',
        self::KATEGORI_PATOLOGIS => 'Kondisi patologis penerima',
    ];

    protected $table = 'philanthropy.assessment_criteria';

    protected $guarded = ['id'];

    /**
     * Kategori yang masih kosong isinya.
     *
     * Daftar kejujuran, sejenis kategoriTanpaBukti() domain R: kategori yang
     * belum diisi kosakatanya TIDAK BISA disurvei, dan lebih baik itu
     * terlihat di layar daripada tersembunyi sebagai pertanyaan yang tak
     * pernah muncul di formulir.
     *
     * @return list<string>
     */
    public static function kategoriKosong(): array
    {
        $terisi = static::query()->where('is_active', true)
            ->distinct()->pluck('category')->all();

        return array_values(array_diff(self::KATEGORI, $terisi));
    }

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }
}
