<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SecurityIncident extends Model
{
    public const SEVERITIES = ['low', 'medium', 'high', 'critical'];

    public const STATUSES = ['open', 'investigating', 'contained', 'resolved'];

    protected $guarded = [];

    protected $casts = ['detected_at' => 'datetime', 'resolved_at' => 'datetime'];

    public function reporter()
    {
        return $this->belongsTo(User::class, 'reported_by');
    }
}
