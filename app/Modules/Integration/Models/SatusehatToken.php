<?php

namespace App\Modules\Integration\Models;

use Illuminate\Database\Eloquent\Model;

class SatusehatToken extends Model
{
    public $timestamps = false;
    public $incrementing = false;

    protected $table = 'integration.satusehat_tokens';
    protected $primaryKey = 'key';
    protected $keyType = 'string';

    protected $fillable = ['key', 'access_token', 'expires_at', 'obtained_at'];

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'obtained_at' => 'datetime',
        ];
    }
}
