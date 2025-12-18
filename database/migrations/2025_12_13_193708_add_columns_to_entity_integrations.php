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
        Schema::table('entity_integrators', function (Blueprint $table) {
            $table->string('token_session')->nullable()->after('mac');
            $table->dateTime('token_session_expires_at')->nullable()->after('token_session');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('entity_integrators', function (Blueprint $table) {
            $table->dropColumn(['token_session', 'token_session_expires_at']);
        });
    }
};
