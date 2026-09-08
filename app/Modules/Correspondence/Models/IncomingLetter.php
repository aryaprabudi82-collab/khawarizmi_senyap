<?php

namespace App\Modules\Correspondence\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class IncomingLetter extends Model
{
    public const STATUS_DITERIMA = 'diterima';

    public const STATUS_DIDISPOSISIKAN = 'didisposisikan';

    public const STATUS_DIARSIPKAN = 'diarsipkan';

    public const BALAS_TIDAK_PERLU = 'tidak-perlu';

    public const BALAS_MENUNGGU = 'menunggu';

    public const BALAS_SUDAH = 'sudah-dibalas';

    /**
     * Dua sumbu yang sebelumnya bercampur dalam satu kolom.
     *
     * SIFAT menjawab seberapa terbatas surat boleh dibaca; DERAJAT
     * menjawab seberapa cepat ia harus ditangani. Surat rahasia yang juga
     * mendesak adalah keadaan yang lazim, dan satu kolom tidak bisa
     * menyatakannya.
     */
    public const SIFAT = ['biasa', 'terbatas', 'rahasia', 'sangat-rahasia'];

    public const DERAJAT = ['biasa', 'segera', 'amat-segera'];

    protected $table = 'correspondence.incoming_letters';

    protected $guarded = ['id'];

    public function dispositions(): HasMany
    {
        return $this->hasMany(LetterDisposition::class, 'incoming_letter_id')->orderBy('sequence');
    }

    public function classification(): BelongsTo
    {
        return $this->belongsTo(LetterClassification::class, 'classification_id');
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(LetterLocation::class, 'location_id');
    }

    public function reply(): BelongsTo
    {
        return $this->belongsTo(OutgoingLetter::class, 'replied_by_letter_id');
    }

    /**
     * Tenggat balas lewat dan belum dibalas. DIHITUNG, bukan status
     * keempat: status yang disimpan menuntut ada yang menjalankannya tiap
     * hari, dan surat yang lewat tenggat pada hari sistem itu mati akan
     * selamanya tampak tepat waktu.
     */
    public function terlambatDibalas(): bool
    {
        return $this->reply_status === self::BALAS_MENUNGGU
            && $this->reply_due_date !== null
            && $this->reply_due_date->isBefore(now()->startOfDay());
    }

    protected function casts(): array
    {
        return [
            'received_at' => 'date',
            'reply_due_date' => 'date',
        ];
    }
}
