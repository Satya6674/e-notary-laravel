<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An Admin's authenticator-app factor. The secret and the (hashed) recovery codes are encrypted at
 * rest with APP_KEY. Never serialised: both are hidden.
 */
class AdminMfaFactor extends Model
{
    protected $guarded = ['id'];

    protected $hidden = ['secret', 'recovery_codes'];

    protected function casts(): array
    {
        return [
            'secret' => 'encrypted',
            'recovery_codes' => 'encrypted:array',
            'confirmed_at' => 'datetime',
            'last_used_at' => 'datetime',
            'last_used_step' => 'integer',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isConfirmed(): bool
    {
        return $this->confirmed_at !== null;
    }
}
