<?php

namespace App\Modules\Correspondence\Services;

use App\Modules\Correspondence\Models\IncomingLetter;
use App\Modules\Correspondence\Models\OutgoingLetter;

class LetterService
{
    public function __construct(private readonly NumberAllocator $numbers) {}

    public function recordIncoming(array $data, int $recordedBy): IncomingLetter
    {
        return IncomingLetter::query()->create($data + [
            'letter_number' => $this->numbers->allocate('SM'),
            'status' => IncomingLetter::STATUS_DITERIMA,
            'recorded_by' => $recordedBy,
        ]);
    }

    public function disposition(IncomingLetter $letter, string $forwardedTo): IncomingLetter
    {
        if ($letter->status !== IncomingLetter::STATUS_DITERIMA) {
            throw new CorrespondenceException('Surat ini sudah didisposisikan atau diarsipkan.');
        }

        $letter->update(['status' => IncomingLetter::STATUS_DIDISPOSISIKAN, 'forwarded_to' => $forwardedTo]);

        return $letter->refresh();
    }

    public function archiveIncoming(IncomingLetter $letter): IncomingLetter
    {
        if ($letter->status === IncomingLetter::STATUS_DIARSIPKAN) {
            throw new CorrespondenceException('Surat ini sudah diarsipkan.');
        }

        $letter->update(['status' => IncomingLetter::STATUS_DIARSIPKAN]);

        return $letter->refresh();
    }

    public function draftOutgoing(array $data, int $createdBy): OutgoingLetter
    {
        return OutgoingLetter::query()->create($data + [
            'letter_number' => $this->numbers->allocate('SK'),
            'status' => OutgoingLetter::STATUS_DRAFT,
            'created_by' => $createdBy,
        ]);
    }

    public function send(OutgoingLetter $letter): OutgoingLetter
    {
        if (! $letter->isDraft()) {
            throw new CorrespondenceException('Surat ini sudah terkirim.');
        }

        $letter->update(['status' => OutgoingLetter::STATUS_TERKIRIM, 'sent_at' => now()->toDateString()]);

        return $letter->refresh();
    }
}
