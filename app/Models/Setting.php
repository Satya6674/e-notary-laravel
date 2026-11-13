<?php

namespace App\Models;

use App\Support\Platform;
use Illuminate\Database\Eloquent\Model;

/** Admin-editable key/value settings. Reads fall back to the default before the table exists. */
class Setting extends Model
{
    public const DEFAULTS = [
        'portal_notice'        => '',
        'support_email'        => '',
        'default_locale'       => 'en',
        'appointments_enabled' => '1',
    ];

    protected $primaryKey = 'key';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    public static function get(string $key): string
    {
        if (! Platform::schemaReady()) {
            return self::DEFAULTS[$key] ?? '';
        }

        return (string) (static::find($key)?->value ?? self::DEFAULTS[$key] ?? '');
    }

    public static function put(string $key, ?string $value): void
    {
        static::updateOrCreate(['key' => $key], ['value' => $value]);
    }
}
