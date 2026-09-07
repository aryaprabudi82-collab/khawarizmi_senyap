<?php

namespace App\Modules\Blood\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Penelusuran balik (look-back) atas seorang donor.
 *
 * Inilah alasan keterlacakan kantong darah ada sama sekali: ketika
 * seorang donor kemudian diketahui reaktif, seluruh kantong yang pernah
 * berasal darinya — termasuk komponen hasil pemisahan — harus ditemukan
 * dan ditarik, dan pasien yang sudah menerimanya harus bisa disebutkan.
 */
class LookbackInvestigation extends Model
{
    protected $table = 'blood.lookback_investigations';

    protected $guarded = ['id'];

    public const BERJALAN = 'berjalan';

    public const SELESAI = 'selesai';

    public const PEMICU = [
        'skrining-reaktif' => 'Hasil skrining donasi berikutnya reaktif',
        'laporan-pasien' => 'Laporan dugaan infeksi pada penerima',
        'laporan-donor' => 'Donor melaporkan sendiri keadaannya',
        'temuan-lain' => 'Temuan lain',
    ];

    protected function casts(): array
    {
        return [
            'triggered_at' => 'datetime',
            'closed_at' => 'datetime',
            'unit_numbers' => 'array',
            'affected_patients' => 'array',
        ];
    }

    public function donor(): BelongsTo
    {
        return $this->belongsTo(Donor::class, 'donor_id');
    }

    public function isOpen(): bool
    {
        return $this->status === self::BERJALAN;
    }

    /**
     * Ada pasien yang sudah terlanjur menerima darah dari donor ini.
     *
     * Merekalah yang harus dihubungi, dan angka inilah yang menentukan
     * apakah penelusuran ini sekadar penarikan stok atau sudah menjadi
     * urusan klinis.
     */
    public function hasAffectedPatients(): bool
    {
        return $this->units_already_issued > 0;
    }

    /**
     * Kantong yang ditemukan tapi belum ditarik maupun terlanjur
     * dikeluarkan.
     *
     * DIHITUNG dari ketiga angka yang tercatat — bukan kolom keempat
     * yang bisa berbeda dari ketiganya.
     */
    public function pendingRecall(): int
    {
        return max(0, $this->units_found - $this->units_recalled - $this->units_already_issued);
    }
}
