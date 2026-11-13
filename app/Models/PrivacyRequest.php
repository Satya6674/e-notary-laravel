<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** A person's request about their own data. An administrator handles each one by hand. */
class PrivacyRequest extends Model
{
    public const TYPES = ['access', 'correction', 'deletion', 'objection'];

    public const STATUSES = ['open', 'in_progress', 'completed', 'declined'];

    protected $guarded = [];

    protected $casts = ['resolved_at' => 'datetime'];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function resolver()
    {
        return $this->belongsTo(User::class, 'resolved_by');
    }
}
