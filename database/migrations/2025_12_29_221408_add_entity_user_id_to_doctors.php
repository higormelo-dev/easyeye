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
        Schema::table('doctors', function (Blueprint $table) {
            $table->dropColumn('entity_id');
            $table->dropColumn('user_id');
            $table->foreignUuid('entity_user_id')
                ->constrained('entity_users')
                ->cascadeOnDelete()
                ->after('id');
            $table->unique(['entity_user_id', 'code'], 'doctors_entity_user_id_code_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('doctors', function (Blueprint $table) {
            $table->dropColumn('entity_user_id');
        });
    }
};
