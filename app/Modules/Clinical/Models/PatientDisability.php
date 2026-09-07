<?php

namespace App\Modules\Clinical\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Cacat fisik / disabilitas yang dimiliki seorang pasien.
 *
 * INILAH SEPARUH YANG HILANG DI KHANZA: cacat_fisik di sana hanya
 * master (id, nama_cacat), tanpa satu pun tabel yang mencatat pasien
 * mana punya cacat yang mana.
 *
 * MELEKAT PADA PASIEN, bukan pada kunjungan — penyesuaian pelayanan
 * tidak boleh perlu ditemukan ulang tiap kali pasien datang.
 */
class PatientDisability extends Model
{
    use SoftDeletes;

    protected $table = 'clinical.patient_disabilities';

    protected $guarded = ['id'];

    public const AKTIF = 'aktif';

    public const PULIH = 'pulih';

    public const DIKOREKSI = 'dikoreksi';

    public const BAWAAN = 'bawaan';

    public const DIDAPAT = 'didapat';

    protected function casts(): array
    {
        return [
            'onset_on' => 'date',
            'recorded_at' => 'datetime',
        ];
    }

    public function isActive(): bool
    {
        return $this->status === self::AKTIF;
    }

    public function isCongenital(): bool
    {
        return $this->onset === self::BAWAAN;
    }

    /**
     * Pelayanan pada pasien ini menuntut penyesuaian yang sudah dicatat.
     *
     * Alat bantu ikut dihitung: pasien berkursi roda menuntut penyesuaian
     * ruang dan pemindahan meski cacatnya sendiri dinilai ringan.
     */
    public function needsServiceAdjustment(): bool
    {
        return $this->isActive()
            && (filled($this->service_adjustment) || filled($this->assistive_device));
    }
}
