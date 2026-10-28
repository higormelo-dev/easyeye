<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class() extends Migration {
    public function up(): void
    {
        Schema::table('report_setting_variables', function (Blueprint $table) {
            $table->string('label', 100)
                ->nullable()
                ->after('placeholder');

            $table->string('group', 30)
                ->nullable()
                ->after('label');

            $table->unsignedSmallInteger('sort_order')
                ->default(0)
                ->after('default_value');

            $table->string('description', 255)
                ->nullable()
                ->after('sort_order');

            // ── Indexes ─────────────────────────────────────────────────────
            $table->index(['report_setting_content_id', 'active'], 'idx_rsv_content_active');
        });
    }

    public function down(): void
    {
        Schema::table('report_setting_variables', function (Blueprint $table) {
            $table->dropIndex('idx_rsv_content_active');
            $table->dropColumn(['label', 'group', 'sort_order', 'description']);
        });
    }
};
