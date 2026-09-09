<?php

namespace App\Modules\Philanthropy\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Asesmen kelayakan seorang calon penerima dana kesehatan.
 *
 * Inilah yang dilayani keenam belas kosakata domain T — dan yang tidak
 * pernah dibangun Khanza: kosakatanya ada semua, instrumennya tidak ada.
 *
 * PUTUSANNYA PUTUSAN MANUSIA. Bobot boleh diisi dan totalnya dihitung, tapi
 * total tidak pernah berubah sendiri jadi "layak"/"tidak layak". Ambang yang
 * ditebak sistem akan menolak keluarga sungguhan dengan angka yang tidak
 * pernah ditetapkan siapa pun, dan yang ditolak tidak punya siapa-siapa
 * untuk ditanyai.
 */
class Assessment extends Model
{
    public const PUTUSAN_BELUM = 'belum-diputuskan';

    public const PUTUSAN_LAYAK = 'layak';

    public const PUTUSAN_TIDAK_LAYAK = 'tidak-layak';

    public const PUTUSAN = [self::PUTUSAN_BELUM, self::PUTUSAN_LAYAK, self::PUTUSAN_TIDAK_LAYAK];

    protected $table = 'philanthropy.assessments';

    protected $guarded = ['id'];

    public function recipient(): BelongsTo
    {
        return $this->belongsTo(Recipient::class, 'recipient_id');
    }

    public function answers(): HasMany
    {
        return $this->hasMany(AssessmentAnswer::class, 'assessment_id');
    }

    public function disbursements(): HasMany
    {
        return $this->hasMany(Disbursement::class, 'assessment_id');
    }

    public function sudahDiputuskan(): bool
    {
        return $this->decision !== self::PUTUSAN_BELUM;
    }

    /**
     * Kategori yang belum dijawab sama sekali.
     *
     * TIDAK memblokir putusan. Survei rumah sering tidak lengkap karena
     * alasan yang sah — penghuni tidak ada, ruangan tidak boleh dilihat —
     * dan menahan putusan sampai enam belas kategori terisi akan menahan
     * bantuan atas nama kerapian formulir. Yang perlu adalah putusannya
     * TAHU apa saja yang tidak ditanyakan.
     *
     * @return list<string>
     */
    public function kategoriBelumDijawab(): array
    {
        $terjawab = $this->answers
            ->filter(fn (AssessmentAnswer $j) => $j->criteria_id !== null)
            ->pluck('category')->all();

        return array_values(array_diff(AssessmentCriterion::KATEGORI, $terjawab));
    }

    /**
     * Total bobot jawaban yang bobotnya terisi.
     *
     * Null kalau tidak ada satu pun jawaban berbobot: nol dan "tidak ada
     * bobot sama sekali" adalah dua keadaan yang berbeda, dan menyamakannya
     * akan menampilkan skor 0 untuk asesmen yang sebenarnya tidak diskor.
     */
    public function totalBobot(): ?int
    {
        $berbobot = $this->answers->filter(fn (AssessmentAnswer $j) => $j->weight !== null);

        return $berbobot->isEmpty() ? null : (int) $berbobot->sum('weight');
    }

    protected function casts(): array
    {
        return [
            'assessed_on' => 'date',
            'decided_at' => 'datetime',
            'recommended_amount' => 'decimal:2',
        ];
    }
}
