<?php

namespace App\Modules\Philanthropy\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Jawaban satu kategori pada satu asesmen.
 *
 * `criteria_id` BOLEH KOSONG dan itu bukan kelalaian: kategori yang belum
 * disurvei berbeda dari kategori yang disurvei lalu tidak menemukan apa-apa,
 * dan menyamakan keduanya membuat surveyor tidak bisa menandai bahwa satu
 * pertanyaan memang tidak sempat ditanyakan.
 *
 * Label dan bobotnya SALINAN, dibekukan saat menjawab: kriteria yang
 * dinonaktifkan atau diubah kalimatnya tidak boleh mengubah bunyi asesmen
 * yang sudah diputuskan — putusan atas nasib orang harus tetap bisa dibaca
 * sebagaimana ia diambil.
 */
class AssessmentAnswer extends Model
{
    protected $table = 'philanthropy.assessment_answers';

    protected $guarded = ['id'];

    public function assessment(): BelongsTo
    {
        return $this->belongsTo(Assessment::class, 'assessment_id');
    }

    public function criterion(): BelongsTo
    {
        return $this->belongsTo(AssessmentCriterion::class, 'criteria_id');
    }

    public function terjawab(): bool
    {
        return $this->criteria_id !== null;
    }
}
