<?php

namespace App\Modules\Clinical\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Pemantauan reaksi transfusi.
 *
 * NOMOR KANTONG BELUM BISA DIPERIKSA terhadap kantong yang benar-benar
 * dikeluarkan unit transfusi darah: domain N belum digarap. Disimpan apa
 * adanya, dan pemeriksaannya ditambahkan saat domain N dibangun —
 * bukan dikarang sekarang.
 */
class TransfusionMonitoring extends Model
{
    use SoftDeletes;

    protected $table = 'clinical.transfusion_monitorings';

    protected $guarded = ['id'];

    /**
     * Tahap pemantauan baku.
     *
     * Lima belas menit pertama adalah tahap paling menentukan: reaksi
     * hemolitik akut umumnya muncul di situ, dan itulah alasan tahapnya
     * jadi kosakata tertutup alih-alih waktu bebas.
     */
    public const TAHAP = [
        'sebelum' => 'Sebelum transfusi',
        '15-menit' => '15 menit pertama',
        'selama' => 'Selama transfusi',
        'selesai' => 'Segera setelah selesai',
        '1-jam-setelah' => 'Satu jam setelah selesai',
    ];

    public const TANDA_REAKSI = [
        'demam' => 'Demam',
        'menggigil' => 'Menggigil',
        'gatal' => 'Gatal',
        'urtikaria' => 'Urtikaria',
        'sesak' => 'Sesak napas',
        'nyeri-dada' => 'Nyeri dada',
        'nyeri-pinggang' => 'Nyeri pinggang',
        'mual-muntah' => 'Mual atau muntah',
        'hipotensi' => 'Hipotensi',
        'urine-gelap' => 'Urine berwarna gelap',
        'lainnya' => 'Lainnya',
    ];

    /** Tanda yang menuntut transfusi dihentikan seketika. */
    public const TANDA_BERAT = ['sesak', 'nyeri-dada', 'nyeri-pinggang', 'hipotensi', 'urine-gelap'];

    protected function casts(): array
    {
        return [
            'observed_at' => 'datetime',
            'reaction_occurred' => 'boolean',
            'reaction_signs' => 'array',
        ];
    }

    /** @return array<int, string> */
    public function severeSigns(): array
    {
        return array_values(array_intersect($this->reaction_signs ?? [], self::TANDA_BERAT));
    }

    /**
     * Reaksi dengan tanda yang menuntut penghentian seketika.
     *
     * Disebutkan, bukan ditegakkan sebagai larangan: yang menghentikan
     * transfusi adalah perawat di samping pasien, dan sistem yang
     * menolak mencatat tidak menghentikan apa pun.
     */
    public function needsImmediateStop(): bool
    {
        return $this->reaction_occurred === true && $this->severeSigns() !== [];
    }
}
