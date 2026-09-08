<?php

namespace App\Modules\Correspondence\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PatientConsent extends Model
{
    public const STATUS_AKTIF = 'aktif';

    public const STATUS_DIBATALKAN = 'dibatalkan';

    /**
     * Keputusan punya TIGA keadaan. 'belum-dikonfirmasi' bukan sinonim
     * penolakan: formulir yang sudah dibuat tapi belum ditandatangani
     * berbeda dari formulir yang ditandatangani dengan penolakan, dan
     * hanya yang kedua boleh dihitung sebagai penolakan pasien.
     */
    public const KEPUTUSAN_SETUJU = 'setuju';

    public const KEPUTUSAN_MENOLAK = 'menolak';

    public const KEPUTUSAN_BELUM = 'belum-dikonfirmasi';

    public const KEPUTUSAN = [self::KEPUTUSAN_SETUJU, self::KEPUTUSAN_MENOLAK, self::KEPUTUSAN_BELUM];

    /**
     * Hubungan penanda tangan dengan pasien — batasnya dari Permenkes
     * 290/2008 pasal 1 angka 5, bukan karangan sendiri.
     */
    public const HUBUNGAN = [
        'diri-sendiri', 'suami', 'istri', 'ayah', 'ibu',
        'anak', 'saudara-kandung', 'pengampu', 'lainnya',
    ];

    public const JENIS_MEMILIH_DPJP = 'memilih-dpjp';

    public const TYPES = [
        'tindakan', 'penolakan-anjuran-medis', 'resusitasi', 'umum',
        'pemeriksaan-hiv', 'penundaan-pelayanan', 'rawat-inap', 'pulang-permintaan-sendiri',
        'pernyataan-pasien-umum', self::JENIS_MEMILIH_DPJP,
    ];

    protected $table = 'correspondence.patient_consents';

    protected $guarded = ['id'];

    public function items(): HasMany
    {
        return $this->hasMany(ConsentInformationItem::class, 'consent_id')->orderBy('position');
    }

    /**
     * Butir wajib yang belum sempat dijelaskan sama sekali.
     *
     * Dipakai untuk menahan persetujuan, bukan penolakan — lihat catatan
     * ketaksimetrisan di ConsentService.
     *
     * @return list<string>
     */
    public function butirBelumDijelaskan(): array
    {
        return $this->items
            ->filter(fn (ConsentInformationItem $butir) => $butir->is_required && $butir->confirmed === null)
            ->pluck('label')
            ->values()
            ->all();
    }

    public function ditandatanganiSendiri(): bool
    {
        return $this->signer_relationship === 'diri-sendiri';
    }

    protected function casts(): array
    {
        return [
            'signed_at' => 'datetime',
            'signer_birth_date' => 'date',
        ];
    }
}
