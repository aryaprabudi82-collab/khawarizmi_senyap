<?php

namespace App\Modules\Clinical\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Serah terima pasien antar ruang.
 *
 * ALAT YANG MENYERTAI PASIEN ADALAH DAFTAR: Khanza memakai enum MySQL,
 * sehingga pasien dengan oksigen portabel sekaligus infus sekaligus
 * kateter urin hanya bisa mencatat salah satunya — dan ruangan penerima
 * membutuhkan daftar lengkapnya.
 */
class PatientTransfer extends Model
{
    use SoftDeletes;

    protected $table = 'clinical.patient_transfers';

    protected $guarded = ['id'];

    public const LAIN_LAIN = 'lain-lain';

    public const INDIKASI = [
        'kondisi-stabil' => 'Kondisi pasien stabil',
        'kondisi-tetap' => 'Kondisi pasien tidak ada perubahan',
        'kondisi-memburuk' => 'Kondisi pasien memburuk',
        'fasilitas-kurang' => 'Fasilitas kurang memadai',
        'butuh-fasilitas-lebih' => 'Butuh fasilitas lebih baik',
        'butuh-tenaga-lebih-ahli' => 'Butuh tenaga yang lebih ahli',
        'tenaga-kurang' => 'Tenaga kurang',
        self::LAIN_LAIN => 'Lain-lain',
    ];

    public const CARA_ANGKUT = [
        'kursi-roda' => 'Kursi roda',
        'tempat-tidur' => 'Tempat tidur',
        'brankar' => 'Brankar',
        'jalan-sendiri' => 'Jalan sendiri',
    ];

    /** Kosakata tertutup, diturunkan dari enum peralatan_yang_menyertai Khanza. */
    public const PERALATAN = [
        'oksigen-portabel' => 'Oksigen portabel',
        'infus' => 'Infus',
        'ngt' => 'Selang nasogastrik (NGT)',
        'syringe-pump' => 'Syringe pump',
        'suction' => 'Suction',
        'kateter-urin' => 'Kateter urin',
        'drain' => 'Drain',
        'monitor' => 'Monitor pasien',
        'ventilator-transport' => 'Ventilator transport',
    ];

    /**
     * Alat yang menandakan pasien tidak boleh dipindah tanpa pendamping
     * yang mampu menanganinya.
     *
     * Bukan larangan: daftar ini dipakai service untuk menuntut catatan,
     * bukan untuk menolak pemindahan yang mungkin justru menyelamatkan.
     */
    public const PERALATAN_BERISIKO = ['ventilator-transport', 'syringe-pump', 'oksigen-portabel'];

    protected function casts(): array
    {
        return [
            'transferred_at' => 'datetime',
            'diagnoses' => 'array',
            'accompanying_equipment' => 'array',
        ];
    }

    /** Pasien berpindah unit, bukan sekadar berpindah kamar. */
    public function crossesUnit(): bool
    {
        return $this->from_unit_name !== null
            && $this->to_unit_name !== null
            && $this->from_unit_name !== $this->to_unit_name;
    }

    /** @return array<int, string> */
    public function riskyEquipment(): array
    {
        return array_values(array_intersect(
            $this->accompanying_equipment ?? [],
            self::PERALATAN_BERISIKO,
        ));
    }
}
