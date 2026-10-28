<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class() extends Migration {
    public function up(): void
    {
        Schema::table('medical_record_documentations', function (Blueprint $table) {
            $table->unsignedInteger('template_version')
                ->nullable()
                ->after('report_setting_content_id');
        });
    }

    public function down(): void
    {
        Schema::table('medical_record_documentations', function (Blueprint $table) {
            $table->dropColumn('template_version');
        });
    }
};
