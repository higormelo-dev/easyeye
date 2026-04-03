<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\{DB, Schema};

return new class() extends Migration {
    public function up(): void
    {
        Schema::table('report_settings', function (Blueprint $table) {
            $table->foreignUuid('report_category_id')
                ->nullable()
                ->after('entity_id')
                ->constrained('report_categories')
                ->nullOnDelete();

            $table->foreignUuid('source_setting_id')
                ->nullable()
                ->after('report_category_id')
                ->constrained('report_settings')
                ->nullOnDelete();

            $table->unsignedInteger('source_version')
                ->nullable()
                ->after('source_setting_id');

            $table->unsignedInteger('version')
                ->default(1)
                ->after('source_version');

            $table->string('status', 20)
                ->default('published')
                ->after('version');

            $table->timestamp('published_at')
                ->nullable()
                ->after('status');

            $table->text('description')
                ->nullable()
                ->after('title');

            // ── Indexes ─────────────────────────────────────────────────────
            $table->index(['entity_id', 'active'], 'idx_rs_entity_active');
            $table->index(['entity_id', 'status'], 'idx_rs_entity_status');
            $table->index('report_category_id', 'idx_rs_category');
            $table->index('source_setting_id', 'idx_rs_source');
        });

        // Backfill: set existing global templates to published
        DB::table('report_settings')
            ->whereNull('entity_id')
            ->update([
                'status'       => 'published',
                'published_at' => now(),
            ]);
    }

    public function down(): void
    {
        Schema::table('report_settings', function (Blueprint $table) {
            $table->dropIndex('idx_rs_entity_active');
            $table->dropIndex('idx_rs_entity_status');
            $table->dropIndex('idx_rs_category');
            $table->dropIndex('idx_rs_source');

            $table->dropConstrainedForeignId('report_category_id');
            $table->dropConstrainedForeignId('source_setting_id');
            $table->dropColumn([
                'source_version',
                'version',
                'status',
                'published_at',
                'description',
            ]);
        });
    }
};
