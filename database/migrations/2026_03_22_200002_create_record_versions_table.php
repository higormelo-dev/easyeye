<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::create('record_versions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('entity_id')->nullable()->constrained('entities')->nullOnDelete();
            $table->foreignUuid('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('versionable_type');
            $table->uuid('versionable_id');
            $table->unsignedInteger('version');
            $table->json('data');
            $table->string('reason')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['versionable_type', 'versionable_id', 'version']);
            $table->index(['versionable_type', 'versionable_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('record_versions');
    }
};
