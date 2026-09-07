<?php

namespace App\Modules\Clinical\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Penilaian pemulihan pasca anestesi, menyambungkan satu jawaban
 * instrumen (Aldrete/Bromage/Steward) ke operasinya.
 *
 * SKORNYA TIDAK DISIMPAN DI SINI. Khanza menyimpan label, angka, dan
 * totalnya sekaligus di skor_aldrette_pasca_anestesi — tiga tempat untuk
 * satu kebenaran. Di sini skornya hidup di form_responses, sudah dihitung
 * dari jawaban dan dibekukan bersama versi templatenya; yang disimpan
 * cuma tautan, urutan, dan keputusan yang diambil atasnya.
 */
class RecoveryAssessment extends Model
{
    protected $table = 'clinical.recovery_assessments';

    protected $guarded = ['id'];

    public const ALDRETE = 'skor-aldrete';

    public const BROMAGE = 'skor-bromage';

    public const STEWARD = 'skor-steward';

    /** Instrumen pemulihan yang sah dipakai di sini. */
    public const INSTRUMEN = [
        self::ALDRETE => 'Aldrete (pasca anestesi umum)',
        self::BROMAGE => 'Bromage (pasca anestesi spinal/epidural)',
        self::STEWARD => 'Steward (pasca anestesi pada anak)',
    ];

    /**
     * Ambang Aldrete yang lazim dipakai sebagai syarat pindah dari ruang
     * pulih.
     *
     * BUKAN PENGHALANG KERAS. Ambang ini praktik yang lazim, tapi
     * keputusan memindahkan pasien dengan skor di bawahnya adalah
     * kebijakan rumah sakit dan pertimbangan dokter anestesi — bukan
     * aturan yang boleh dikarang program. Yang ditegakkan di sini:
     * memindahkan pasien di bawah ambang WAJIB menyebut alasannya,
     * supaya keputusannya tercatat alih-alih dilarang atau dibiarkan
     * lewat tanpa jejak.
     */
    public const AMBANG_ALDRETE = 8;

    public const LANJUT_OBSERVASI = 'lanjut-observasi';

    public const PINDAH_BANGSAL = 'pindah-bangsal';

    public const PINDAH_ICU = 'pindah-icu';

    public const PULANG = 'pulang';

    /** Keputusan yang berarti pasien meninggalkan ruang pulih. */
    public const KELUAR_RUANG_PULIH = [self::PINDAH_BANGSAL, self::PULANG];

    protected function casts(): array
    {
        return [
            'assessed_at' => 'datetime',
        ];
    }

    public function formResponse(): BelongsTo
    {
        return $this->belongsTo(FormResponse::class, 'form_response_id');
    }

    public function anaesthesiaRecord(): BelongsTo
    {
        return $this->belongsTo(AnaesthesiaRecord::class, 'anaesthesia_record_id');
    }

    /** Skor dibaca dari jawaban formulirnya, tidak disalin ke sini. */
    public function score(): ?int
    {
        return $this->formResponse?->score;
    }
}
