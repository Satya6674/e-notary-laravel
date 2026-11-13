<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Audit B-12. Migration 000016 made security_incidents.detected_at a required TIMESTAMP, and
 * MariaDB silently gives such a column ON UPDATE CURRENT_TIMESTAMP: every later save (marking an
 * incident resolved, say) rewrote when it was detected. This keeps every stored value and only
 * changes the column definition, the same fix migration 000001 of 2026-11-01 applied to OTPs.
 * The application always sets the value itself, so the column can be nullable.
 *
 * SQLite has no ON UPDATE and needs nothing.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            DB::statement('ALTER TABLE security_incidents MODIFY detected_at TIMESTAMP NULL DEFAULT NULL');
        }
    }

    public function down(): void
    {
        if (in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            DB::statement('ALTER TABLE security_incidents MODIFY detected_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP');
        }
    }
};
