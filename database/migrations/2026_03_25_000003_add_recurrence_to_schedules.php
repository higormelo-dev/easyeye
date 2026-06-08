<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class() extends Migration {
    public function up(): void
    {
        Schema::table('schedules', function (Blueprint $table) {
            $table->uuid('recurrence_group_id')->nullable()->after('visit_id');
            $table->string('recurrence_type', 10)->nullable()->after('recurrence_group_id'); // weekly | monthly
            $table->date('recurrence_until')->nullable()->after('recurrence_type');

            $table->index('recurrence_group_id');
        });
    }

    public function down(): void
    {
        Schema::table('schedules', function (Blueprint $table) {
            $table->dropIndex(['recurrence_group_id']);
            $table->dropColumn(['recurrence_group_id', 'recurrence_type', 'recurrence_until']);
        });
    }
};
