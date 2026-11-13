<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SupportTicketMessage extends Model
{
    protected $guarded = [];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    /** Only after migration 2026_11_04_000027; check SupportTicketAttachment::supported() first. */
    public function attachments()
    {
        return $this->hasMany(SupportTicketAttachment::class, 'message_id');
    }
}
