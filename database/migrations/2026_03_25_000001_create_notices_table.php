<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::create('notices', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('entity_id')->constrained('entities');
            $table->foreignUuid('author_id')->constrained('entity_users');
            $table->text('content');
            $table->tinyInteger('priority')->default(0); // 0 = normal, 1 = urgente
            $table->boolean('pinned')->default(false);
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();

            $table->index(['entity_id', 'expires_at']);
        });

        Schema::create('notice_reads', function (Blueprint $table) {
            $table->foreignUuid('notice_id')->constrained('notices')->cascadeOnDelete();
            $table->foreignUuid('entity_user_id')->constrained('entity_users')->cascadeOnDelete();
            $table->timestamp('read_at')->useCurrent();

            $table->primary(['notice_id', 'entity_user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notice_reads');
        Schema::dropIfExists('notices');
    }
};
