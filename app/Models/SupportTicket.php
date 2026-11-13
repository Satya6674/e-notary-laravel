<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class SupportTicket extends Model
{
    public const CATEGORIES = ['application', 'documents', 'appointment', 'account', 'other'];

    public const STATUSES = ['open', 'answered', 'closed'];

    /** Needs migration 2026_11_04_000026. */
    public const PRIORITIES = ['low', 'normal', 'high', 'urgent'];

    protected $guarded = [];

    protected $casts = [
        'closed_at' => 'datetime',
    ];

    private static ?bool $triageSupported = null;

    /** Priority, assignment and closed_at exist only after migration 2026_11_04_000026. */
    public static function triageSupported(): bool
    {
        return self::$triageSupported ??= Schema::hasColumn('support_tickets', 'priority');
    }

    public static function forgetSchemaState(): void
    {
        self::$triageSupported = null;
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function request()
    {
        return $this->belongsTo(NotaryRequest::class, 'request_id');
    }

    public function assignee()
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    public function messages()
    {
        return $this->hasMany(SupportTicketMessage::class, 'ticket_id')->oldest();
    }

    public static function generateNumber(): string
    {
        do {
            $number = 'ST-'.now(\App\Support\Platform::DISPLAY_TIMEZONE)->format('Ymd').'-'.Str::upper(Str::random(5));
        } while (static::where('ticket_number', $number)->exists());

        return $number;
    }
}
