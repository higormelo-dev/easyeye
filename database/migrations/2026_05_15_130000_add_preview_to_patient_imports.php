<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class() extends Migration {
    public function up(): void
    {
        Schema::table('patient_imports', function (Blueprint $table) {
            $table->json('preview')->nullable()->after('original_name');
            $table->timestamp('confirmed_at')->nullable()->after('started_at');
        });
    }

    public function down(): void
    {
        Schema::table('patient_imports', function (Blueprint $table) {
            $table->dropColumn(['preview', 'confirmed_at']);
        });
    }
};
