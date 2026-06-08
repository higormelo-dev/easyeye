<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class() extends Migration {
    /**
     * Replace the existing partial unique index with one that also excludes
     * terminal situations (NoShow = 8, Cancelled = 9).
     *
     * Problem: the old index only excluded soft-deleted rows, so a slot that
     * had a cancelled appointment was permanently blocked — no one could book
     * the same doctor + datetime again even after cancellation.
     *
     * Solution: also exclude terminal situations from the uniqueness constraint,
     * meaning a slot becomes available again once its schedule is cancelled/no-show.
     */
    public function up(): void
    {
        DB::statement('DROP INDEX IF EXISTS schedules_doctor_datetime_active_unique');

        DB::statement('
            CREATE UNIQUE INDEX schedules_doctor_datetime_active_unique
                ON schedules (doctor_id, date_time)
             WHERE deleted_at IS NULL
               AND situation NOT IN (8, 9)
        ');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS schedules_doctor_datetime_active_unique');

        DB::statement('
            CREATE UNIQUE INDEX schedules_doctor_datetime_active_unique
                ON schedules (doctor_id, date_time)
             WHERE deleted_at IS NULL
        ');
    }
};
