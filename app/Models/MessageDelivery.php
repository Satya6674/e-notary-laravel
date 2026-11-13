<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * What the platform knows about one outgoing message. "accepted" means the provider
 * took it, not that it arrived. confirmed_at is set only when the person acted on it,
 * by opening the link or entering the code, which is the only proof available here.
 */
class MessageDelivery extends Model
{
    public const QUEUED = 'queued';
    public const SENDING = 'sending';
    public const ACCEPTED = 'accepted';
    public const FAILED = 'failed';
    public const BLOCKED = 'blocked';
    public const SIMULATED = 'simulated';

    protected $guarded = [];

    protected $casts = ['confirmed_at' => 'datetime'];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
