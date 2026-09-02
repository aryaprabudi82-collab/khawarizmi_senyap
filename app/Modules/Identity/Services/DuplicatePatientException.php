<?php

namespace App\Modules\Identity\Services;

use App\Modules\Identity\Models\Patient;
use RuntimeException;

class DuplicatePatientException extends RuntimeException
{
    public function __construct(
        public readonly Patient $existing,
        string $message = 'Pasien dengan identitas ini sudah terdaftar.'
    ) {
        parent::__construct($message);
    }
}
