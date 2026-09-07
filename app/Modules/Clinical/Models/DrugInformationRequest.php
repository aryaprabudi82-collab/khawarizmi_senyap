<?php

namespace App\Modules\Clinical\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Pelayanan informasi obat — pertanyaan tentang obat berikut jawabannya.
 *
 * TIDAK SELALU TENTANG SATU PASIEN: registration_id boleh kosong, karena
 * penanyanya boleh petugas kesehatan yang bertanya soal stabilitas atau
 * interaksi tanpa ada pasien tertentu di hadapannya.
 *
 * LAMA JAWABAN DIHITUNG, TIDAK DISIMPAN — lihat responseBracket().
 */
class DrugInformationRequest extends Model
{
    use SoftDeletes;

    protected $table = 'clinical.drug_information_requests';

    protected $guarded = ['id'];

    public const TERBUKA = 'terbuka';

    public const DIJAWAB = 'dijawab';

    public const DIBATALKAN = 'dibatalkan';

    public const LAIN_LAIN = 'lain-lain';

    /** Empat belas jenis pertanyaan, diambil apa adanya dari enum Khanza. */
    public const JENIS = [
        'identifikasi-obat' => 'Identifikasi obat',
        'interaksi-obat' => 'Interaksi obat',
        'harga-obat' => 'Harga obat',
        'kontraindikasi' => 'Kontraindikasi',
        'cara-pemakaian' => 'Cara pemakaian',
        'stabilitas' => 'Stabilitas',
        'dosis' => 'Dosis',
        'keracunan' => 'Keracunan',
        'efek-samping-obat' => 'Efek samping obat',
        'penggunaan-terapeutik' => 'Penggunaan terapeutik',
        'farmakokinetika' => 'Farmakokinetika',
        'farmakodinamika' => 'Farmakodinamika',
        'ketersediaan-obat' => 'Ketersediaan obat',
        self::LAIN_LAIN => 'Lain-lain',
    ];

    public const METODE = [
        'lisan' => 'Lisan',
        'tertulis' => 'Tertulis',
        'telepon' => 'Telepon',
    ];

    protected function casts(): array
    {
        return [
            'asked_at' => 'datetime',
            'answered_at' => 'datetime',
        ];
    }

    public function isAnswered(): bool
    {
        return $this->status === self::DIJAWAB;
    }

    /**
     * Berapa jam pertanyaan ini menunggu jawaban.
     *
     * Dihitung dari dua waktu yang sudah tercatat, tidak disimpan.
     */
    public function responseHours(): ?float
    {
        if ($this->answered_at === null) {
            return null;
        }

        return round($this->asked_at->floatDiffInHours($this->answered_at), 2);
    }

    /**
     * Kategori kecepatan jawaban — padanan penyampaian_jawaban Khanza,
     * DIHITUNG alih-alih disimpan.
     *
     * Menyimpannya berarti satu kolom yang bisa berkata "Segera" pada
     * pertanyaan yang nyatanya dijawab tiga hari kemudian, dan yang
     * salah selalu ketahuan belakangan.
     */
    public function responseBracket(): ?string
    {
        $jam = $this->responseHours();

        return match (true) {
            $jam === null => null,
            $jam <= 1 => 'segera',
            $jam <= 24 => 'dalam-24-jam',
            default => 'lebih-dari-24-jam',
        };
    }
}
