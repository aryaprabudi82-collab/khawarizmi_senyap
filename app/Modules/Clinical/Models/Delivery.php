<?php

namespace App\Modules\Clinical\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Catatan persalinan.
 *
 * BAYI ADALAH BARIS, BUKAN KOLOM: satu persalinan boleh punya berapa
 * pun bayi. Lihat catatan migrasi.
 *
 * LAMA PERSALINAN DAN JUMLAH PERDARAHAN DIHITUNG, tidak disimpan.
 */
class Delivery extends Model
{
    use SoftDeletes;

    protected $table = 'clinical.deliveries';

    protected $guarded = ['id'];

    public const DRAF = 'draf';

    public const FINAL = 'final';

    public const DIBATALKAN = 'dibatalkan';

    /**
     * Ambang perdarahan pascasalin.
     *
     * 500 mL untuk persalinan pervaginam. Inilah alasan jumlah perdarahan
     * tidak boleh disimpan sebagai kolom tersendiri: keputusan sepenting
     * ini tidak boleh bergantung pada angka yang bisa berbeda dari
     * bagian-bagian yang melahirkannya.
     */
    public const AMBANG_PERDARAHAN_ML = 500;

    public const CARA = [
        'spontan' => 'Spontan',
        'vakum' => 'Ekstraksi vakum',
        'forsep' => 'Ekstraksi forsep',
        'sungsang' => 'Pervaginam sungsang',
        'seksio-sesarea' => 'Seksio sesarea',
    ];

    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'ended_at' => 'datetime',
            'membrane_ruptured_at' => 'datetime',
            'finalized_at' => 'datetime',
        ];
    }

    public function babies(): HasMany
    {
        return $this->hasMany(DeliveryBaby::class, 'delivery_id')->orderBy('birth_order');
    }

    public function isEditable(): bool
    {
        return $this->status === self::DRAF;
    }

    /**
     * Lama persalinan dari jumlah kala yang tercatat.
     *
     * Padanan waktu_persalinan_jumlah Khanza, DIHITUNG. Mengembalikan
     * null bila tidak satu kala pun tercatat — nol akan terbaca sebagai
     * "persalinannya seketika", bukan "belum dicatat".
     */
    public function labourMinutes(): ?int
    {
        $kala = array_filter([
            $this->stage1_minutes, $this->stage2_minutes,
            $this->stage3_minutes, $this->stage4_minutes,
        ], fn ($menit) => $menit !== null);

        return $kala === [] ? null : (int) array_sum($kala);
    }

    /**
     * Jumlah perdarahan dari kala 2, 3, dan 4.
     *
     * Padanan darah_keluar_jumlah Khanza, DIHITUNG.
     */
    public function bloodLossMl(): ?int
    {
        $kala = array_filter([
            $this->blood_loss_stage2_ml, $this->blood_loss_stage3_ml, $this->blood_loss_stage4_ml,
        ], fn ($ml) => $ml !== null);

        return $kala === [] ? null : (int) array_sum($kala);
    }

    /**
     * Apakah perdarahannya sudah mencapai ambang pascasalin.
     *
     * Mengembalikan null saat belum ada satu pun catatan perdarahan:
     * "belum dicatat" berbeda dari "tidak ada perdarahan", dan
     * mengembalikan false untuk keduanya menyembunyikan yang pertama.
     */
    public function isPostpartumHaemorrhage(): ?bool
    {
        $jumlah = $this->bloodLossMl();

        return $jumlah === null ? null : $jumlah >= self::AMBANG_PERDARAHAN_ML;
    }

    /**
     * Berapa jam ketuban sudah pecah saat persalinan selesai.
     *
     * Selisih inilah yang menentukan risiko infeksi — dan yang tidak
     * bisa dihitung dari dua kolom teks jam dan menit milik Khanza.
     */
    public function membraneRuptureHours(): ?float
    {
        if ($this->membrane_ruptured_at === null || $this->ended_at === null) {
            return null;
        }

        return round($this->membrane_ruptured_at->floatDiffInHours($this->ended_at), 1);
    }
}
