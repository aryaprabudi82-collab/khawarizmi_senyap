<?php

namespace App\Modules\Clinical\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Perencanaan pemulangan — apa yang perlu disiapkan pasien dan
 * keluarganya sebelum pulang, disusun sejak awal rawat inap.
 *
 * BANTUAN YANG DIBUTUHKAN BERBENTUK DAFTAR, bukan satu pilihan seperti
 * enum Khanza: pasien yang butuh bantuan mandi sekaligus minum obat
 * tidak perlu memilih salah satu.
 *
 * PERTANYAAN PENGARUH BOLEH NULL: "belum ditanyakan" bukan "tidak".
 */
class DischargePlan extends Model
{
    use SoftDeletes;

    protected $table = 'clinical.discharge_plans';

    protected $guarded = ['id'];

    public const DRAF = 'draf';

    public const FINAL = 'final';

    public const DIBATALKAN = 'dibatalkan';

    /**
     * Tenggat penyusunan rencana pulang, dihitung dari waktu masuk.
     *
     * Bukan aturan teknis melainkan pengingat operasional: rencana yang
     * baru disusun di hari kepulangan tidak memberi keluarga waktu
     * menyiapkan apa pun.
     */
    public const TENGGAT_JAM = 48;

    /** Kosakata tertutup, diturunkan dari enum bantuan_diperlukan_dalam Khanza. */
    public const BANTUAN = [
        'menyiapkan-makanan' => 'Menyiapkan makanan',
        'edukasi-kesehatan' => 'Edukasi kesehatan',
        'makan' => 'Makan',
        'mandi' => 'Mandi',
        'diet' => 'Diet',
        'berpakaian' => 'Berpakaian',
        'menyiapkan-obat' => 'Menyiapkan obat',
        'minum-obat' => 'Minum obat',
        'transportasi' => 'Transportasi',
    ];

    protected function casts(): array
    {
        return [
            'assistance_needed' => 'array',
            'admitted_at' => 'datetime',
            'planned_discharge_on' => 'date',
            'affects_family' => 'boolean',
            'affects_work_or_school' => 'boolean',
            'affects_finance' => 'boolean',
            'anticipated_problems' => 'boolean',
            'finalized_at' => 'datetime',
        ];
    }

    public function isEditable(): bool
    {
        return $this->status === self::DRAF;
    }

    /**
     * Berapa jam setelah pasien masuk rencana ini dibuat.
     *
     * Dihitung, tidak disimpan: keduanya sudah tercatat, dan menyimpan
     * selisihnya berarti satu angka yang bisa berbeda dari sumbernya.
     */
    public function hoursAfterAdmission(): ?float
    {
        if ($this->admitted_at === null || $this->created_at === null) {
            return null;
        }

        return round($this->admitted_at->floatDiffInHours($this->created_at), 1);
    }

    public function isLate(): bool
    {
        $jam = $this->hoursAfterAdmission();

        return $jam !== null && $jam > self::TENGGAT_JAM;
    }
}
