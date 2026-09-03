<?php

namespace App\Modules\Correspondence\Models;

use Illuminate\Database\Eloquent\Model;

class IncomingLetter extends Model
{
    public const STATUS_DITERIMA = 'diterima';
    public const STATUS_DIDISPOSISIKAN = 'didisposisikan';
    public const STATUS_DIARSIPKAN = 'diarsipkan';

    protected $table = 'correspondence.incoming_letters';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['received_at' => 'date'];
    }
}
