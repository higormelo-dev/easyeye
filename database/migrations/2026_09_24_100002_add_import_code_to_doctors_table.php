<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class() extends Migration {
    /**
     * `doctors` has no direct `entity_id` (tenant resolves via
     * entity_user_id -> entity_users.entity_id), so uniqueness of
     * import_code per entity is enforced in the application layer
     * (DoctorImportService), the same way record/record_specialty/color
     * already are in DoctorRequest. This index is for lookup performance
     * only, not a uniqueness guarantee.
     */
    public function up(): void
    {
        Schema::table('doctors', function (Blueprint $table) {
            $table->string('import_code')->nullable();

            $table->index('import_code');
        });
    }

    public function down(): void
    {
        Schema::table('doctors', function (Blueprint $table) {
            $table->dropIndex(['import_code']);
            $table->dropColumn('import_code');
        });
    }
};
