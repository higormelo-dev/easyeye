<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class() extends Migration {
    public function up(): void
    {
        Schema::table('patient_exams', function (Blueprint $table) {
            $table->tinyInteger('laterality')->nullable()->after('name');
        });
    }

    public function down(): void
    {
        Schema::table('patient_exams', function (Blueprint $table) {
            $table->dropColumn('laterality');
        });
    }
};
