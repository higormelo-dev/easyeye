<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class() extends Migration {
    public function up(): void
    {
        Schema::table('patients', function (Blueprint $table) {
            $table->string('import_code')->nullable();

            $table->unique(['entity_id', 'import_code'], 'patients_entity_id_import_code_unique');
        });
    }

    public function down(): void
    {
        Schema::table('patients', function (Blueprint $table) {
            $table->dropUnique('patients_entity_id_import_code_unique');
            $table->dropColumn('import_code');
        });
    }
};
