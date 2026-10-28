<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class() extends Migration {
    public function up(): void
    {
        Schema::table('billing_claims', function (Blueprint $table) {
            $table->foreignUuid('tiss_guide_id')
                ->nullable()
                ->after('schedule_id')
                ->constrained('tiss_guides')
                ->nullOnDelete();
        });

        Schema::table('billing_batches', function (Blueprint $table) {
            $table->foreignUuid('tiss_batch_id')
                ->nullable()
                ->after('covenant_id')
                ->constrained('tiss_batches')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('billing_claims', function (Blueprint $table) {
            $table->dropConstrainedForeignId('tiss_guide_id');
        });

        Schema::table('billing_batches', function (Blueprint $table) {
            $table->dropConstrainedForeignId('tiss_batch_id');
        });
    }
};
