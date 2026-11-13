<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** A session booked by a citizen for one of their applications. */
class Appointment extends Model
{
    public const STATUSES = ['requested', 'confirmed', 'completed', 'cancelled'];

    public const MODES = ['video', 'in_person'];

    protected $guarded = [];

    protected $casts = [
        'scheduled_at' => 'datetime',
    ];

    public function request()
    {
        return $this->belongsTo(NotaryRequest::class, 'request_id');
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function notary()
    {
        return $this->belongsTo(User::class, 'notary_id');
    }

    public function referenceNumber(): string
    {
        return 'APT-'.$this->created_at?->ist('Ymd').'-'.str_pad((string) $this->id, 6, '0', STR_PAD_LEFT);
    }

    public function isOpen(): bool
    {
        return in_array($this->status, ['requested', 'confirmed'], true);
    }
}
