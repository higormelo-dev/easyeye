<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class() extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('user_preferences', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')
                ->unique()
                ->constrained('users')
                ->cascadeOnDelete();
            // Bag flexível — cada recurso "humanização" (item MELHORIA #4) lê/escreve
            // sua própria chave aqui (dashboard_widget_order, favorite_shortcuts,
            // e futuramente news_enabled/playlist_url quando essas rodadas entrarem)
            // em vez de crescer o schema com uma coluna nova por preferência.
            $table->json('data')->default('{}');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('user_preferences');
    }
};
