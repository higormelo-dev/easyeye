<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class() extends Migration {
    public function up(): void
    {
        Schema::table('report_setting_contents', function (Blueprint $table) {
            $table->softDeletes();

            $table->foreignUuid('created_by')
                ->nullable()
                ->after('sort_order')
                ->constrained('users')
                ->nullOnDelete();

            $table->foreignUuid('updated_by')
                ->nullable()
                ->after('created_by')
                ->constrained('users')
                ->nullOnDelete();

            // ── Indexes ─────────────────────────────────────────────────────
            $table->index(['report_setting_id', 'active', 'deleted_at'], 'idx_rsc_setting_active');
            $table->index(['report_setting_id', 'type'], 'idx_rsc_setting_type');
        });
    }

    public function down(): void
    {
        Schema::table('report_setting_contents', function (Blueprint $table) {
            $table->dropIndex('idx_rsc_setting_active');
            $table->dropIndex('idx_rsc_setting_type');
            $table->dropConstrainedForeignId('created_by');
            $table->dropConstrainedForeignId('updated_by');
            $table->dropSoftDeletes();
        });
    }
};
