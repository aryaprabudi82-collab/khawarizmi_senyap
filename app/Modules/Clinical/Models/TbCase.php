<?php

namespace App\Modules\Clinical\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Register program TB (TB-03).
 *
 * "SEMBUH" DAN "PENGOBATAN LENGKAP" BUKAN DUA NAMA UNTUK HAL YANG SAMA:
 * keduanya berarti pengobatan selesai, tapi sembuh menuntut bukti
 * bakteriologis negatif pada akhir pengobatan. Angka kesembuhan yang
 * dilaporkan ke program nasional dihitung dari yang pertama saja.
 */
class TbCase extends Model
{
    use SoftDeletes;

    protected $table = 'clinical.tb_cases';

    protected $guarded = ['id'];

    public const BELUM = 'belum';

    public const SEMBUH = 'sembuh';

    public const PENGOBATAN_LENGKAP = 'pengobatan-lengkap';

    public const GAGAL = 'gagal';

    public const PUTUS_BEROBAT = 'putus-berobat';

    public const MENINGGAL = 'meninggal';

    public const PINDAH = 'pindah';

    /** Hasil akhir menurut program TB nasional. */
    public const HASIL_AKHIR = [
        self::BELUM => 'Belum ada hasil akhir',
        self::SEMBUH => 'Sembuh (dengan bukti bakteriologis negatif)',
        self::PENGOBATAN_LENGKAP => 'Pengobatan lengkap (tanpa bukti bakteriologis akhir)',
        self::GAGAL => 'Gagal',
        self::PUTUS_BEROBAT => 'Putus berobat',
        self::MENINGGAL => 'Meninggal',
        self::PINDAH => 'Pindah',
    ];

    /** Hasil akhir yang dihitung sebagai keberhasilan pengobatan. */
    public const BERHASIL = [self::SEMBUH, self::PENGOBATAN_LENGKAP];

    public const TIPE_DIAGNOSIS = [
        'terkonfirmasi-bakteriologis' => 'Terkonfirmasi bakteriologis',
        'terdiagnosis-klinis' => 'Terdiagnosis klinis',
    ];

    public const RIWAYAT = [
        'baru' => 'Baru',
        'kambuh' => 'Kambuh',
        'gagal' => 'Diobati setelah gagal',
        'putus-berobat' => 'Diobati setelah putus berobat',
        'pindahan' => 'Pindahan',
        'lainnya' => 'Lain-lain',
        'tidak-diketahui' => 'Riwayat sebelumnya tidak diketahui',
    ];

    public const SUMBER_OBAT = [
        'program-tb' => 'Program TB',
        'bayar-sendiri' => 'Bayar sendiri',
        'asuransi' => 'Asuransi',
        'lainnya' => 'Lain-lain',
    ];

    public const RUJUKAN = [
        'inisiatif-sendiri' => 'Inisiatif pasien atau keluarga',
        'kader' => 'Anggota masyarakat atau kader',
        'faskes' => 'Fasilitas kesehatan lain',
        'dokter-praktik-mandiri' => 'Dokter praktik mandiri',
        'poli-lain' => 'Poliklinik lain',
        'lainnya' => 'Lain-lain',
    ];

    /** Batas umur pemakaian sistem skoring TB anak. */
    public const BATAS_UMUR_ANAK = 15;

    protected function casts(): array
    {
        return [
            'registered_on' => 'date',
            'hiv_tested_on' => 'date',
            'treatment_started_on' => 'date',
            'outcome_on' => 'date',
        ];
    }

    public function followups(): HasMany
    {
        return $this->hasMany(TbFollowup::class, 'tb_case_id')->orderBy('examined_on');
    }

    public function isChild(): bool
    {
        return $this->age_years !== null && $this->age_years < self::BATAS_UMUR_ANAK;
    }

    public function isClosed(): bool
    {
        return $this->outcome !== self::BELUM;
    }

    /**
     * Ada bukti bakteriologis negatif pada akhir pengobatan.
     *
     * Inilah satu-satunya yang membedakan "sembuh" dari "pengobatan
     * lengkap", dan karena itu ia dihitung dari pemeriksaannya — bukan
     * dari penanda yang bisa dicentang siapa pun.
     */
    public function hasNegativeEndOfTreatmentSmear(): bool
    {
        return $this->followups
            ->where('phase', TbFollowup::AKHIR_PENGOBATAN)
            ->contains(fn (TbFollowup $f) => $f->isNegative());
    }

    /**
     * Status HIV belum diketahui, jadi tesnya masih perlu ditawarkan.
     *
     * Berbeda dari negatif: pasien TB yang belum dites berbeda dari
     * pasien TB yang hasilnya non-reaktif, dan justru yang pertama yang
     * harus ditawari tes.
     */
    public function needsHivTest(): bool
    {
        return $this->hiv_status === 'tidak-diketahui';
    }
}
