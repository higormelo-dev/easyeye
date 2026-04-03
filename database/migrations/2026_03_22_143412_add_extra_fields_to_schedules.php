<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('schedules', function (Blueprint $table) {
            $table->text('notes')->nullable()->after('cellphone_whatsapp');
            $table->text('cancellation_reason')->nullable()->after('notes');
            $table->dateTime('confirmed_at')->nullable()->after('arrived_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('schedules', function (Blueprint $table) {
            $table->dropColumn(['notes', 'cancellation_reason', 'confirmed_at']);
        });
    }
};
