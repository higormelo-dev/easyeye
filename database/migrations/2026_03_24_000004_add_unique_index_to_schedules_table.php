<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class () extends Migration {
    /**
     * Partial unique index on (doctor_id, date_time) excluding soft-deleted rows.
     * Prevents double-booking at DB level even under concurrent inserts.
     *
     * Supported by: SQLite 3.8.9+, PostgreSQL, MySQL 8.0.13+.
     */
    public function up(): void
    {
        DB::statement('
            CREATE UNIQUE INDEX schedules_doctor_datetime_active_unique
                ON schedules (doctor_id, date_time)
             WHERE deleted_at IS NULL
        ');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS schedules_doctor_datetime_active_unique');
    }
};
