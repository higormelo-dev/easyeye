<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('doctors', function (Blueprint $table) {
            $constraintExists = DB::select("
                SELECT constraint_name
                FROM information_schema.table_constraints
                WHERE table_name = 'doctors'
                AND constraint_name = 'doctors_entity_id_code_unique'
            ");

            if (! empty($constraintExists)) {
                $table->dropUnique('doctors_entity_id_code_unique');
            }

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
            $table->dropUnique('doctors_entity_user_id_code_unique');
            $table->dropColumn('entity_user_id');
            $table->foreignUuid('entity_id')
                ->constrained('entities')
                ->cascadeOnDelete()
                ->after('id');
            $table->foreignUuid('user_id')
                ->constrained('users')
                ->cascadeOnDelete()
                ->after('person_id');
            $table->unique(['entity_id', 'code'], 'doctors_entity_id_code_unique');
        });
    }
};
