<?php

namespace App\Modules\Correspondence\Models;

use Illuminate\Database\Eloquent\Model;

class MedicalCertificate extends Model
{
    public const STATUS_DITERBITKAN = 'diterbitkan';

    public const STATUS_DIBATALKAN = 'dibatalkan';

    public const JENIS_SAKIT_PIHAK_KEDUA = 'sakit_pihak_kedua';

    public const JENIS_RAWAT_INAP = 'rawat_inap';

    /**
     * Jenis yang MENYATAKAN KETIADAAN sesuatu. Hasil pemeriksaannya wajib
     * dicatat karena bisa berlawanan dengan judul suratnya — dan surat
     * yang secara struktur tidak bisa memuat temuan yang tidak diinginkan
     * bukan surat keterangan, ia formulir kelulusan.
     */
    public const JENIS_BERTEMUAN = [
        'bebas_narkoba', 'bebas_tbc', 'buta_warna', 'covid',
        'bebas_tato', 'tidak_hamil',
    ];

    public const TYPES = [
        'sehat', 'sakit', 'berobat',
        'bebas_narkoba', 'bebas_tbc', 'buta_warna', 'layak_terbang', 'kewaspadaan_kesehatan', 'covid', 'cuti_hamil',
        'sakit_pihak_kedua', 'tidak_hamil', 'rawat_inap', 'bebas_tato',
    ];

    protected $table = 'correspondence.medical_certificates';

    protected $guarded = ['id'];

    public function memeriksaSesuatu(): bool
    {
        return in_array($this->certificate_type, self::JENIS_BERTEMUAN, true);
    }

    /**
     * Judul surat diturunkan dari TEMUAN, bukan dari jenisnya.
     *
     * Surat bertipe bebas_tato dengan is_clear=false tidak pernah dicetak
     * berbunyi "bebas". Kalau judulnya ikut jenis, satu-satunya cara
     * menerbitkan hasil yang tidak diinginkan adalah tidak menerbitkannya
     * sama sekali.
     */
    public function judul(): string
    {
        $bersih = [
            'bebas_narkoba' => 'Surat Keterangan Bebas Narkoba',
            'bebas_tbc' => 'Surat Keterangan Bebas TBC',
            'buta_warna' => 'Surat Keterangan Tidak Buta Warna',
            'covid' => 'Surat Keterangan Bebas COVID-19',
            'bebas_tato' => 'Surat Keterangan Bebas Tato',
            'tidak_hamil' => 'Surat Keterangan Tidak Hamil',
        ];

        $bertemuan = [
            'bebas_narkoba' => 'Surat Keterangan Hasil Pemeriksaan Narkoba',
            'bebas_tbc' => 'Surat Keterangan Hasil Pemeriksaan TBC',
            'buta_warna' => 'Surat Keterangan Hasil Pemeriksaan Buta Warna',
            'covid' => 'Surat Keterangan Hasil Pemeriksaan COVID-19',
            'bebas_tato' => 'Surat Keterangan Hasil Pemeriksaan Tato',
            'tidak_hamil' => 'Surat Keterangan Hasil Pemeriksaan Kehamilan',
        ];

        if ($this->memeriksaSesuatu()) {
            return $this->is_clear
                ? $bersih[$this->certificate_type]
                : $bertemuan[$this->certificate_type];
        }

        return [
            'sehat' => 'Surat Keterangan Sehat',
            'sakit' => 'Surat Keterangan Sakit',
            'berobat' => 'Surat Keterangan Berobat',
            'layak_terbang' => 'Surat Keterangan Layak Terbang',
            'kewaspadaan_kesehatan' => 'Surat Kewaspadaan Kesehatan',
            'cuti_hamil' => 'Surat Cuti Hamil',
            'sakit_pihak_kedua' => 'Surat Keterangan Sakit (Pihak Kedua)',
            'rawat_inap' => 'Surat Keterangan Rawat Inap',
        ][$this->certificate_type] ?? 'Surat Keterangan';
    }

    protected function casts(): array
    {
        return [
            'valid_from' => 'date',
            'valid_until' => 'date',
            'issued_at' => 'datetime',
            'is_clear' => 'boolean',
            'third_party_birth_date' => 'date',
        ];
    }
}
