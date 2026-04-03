<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::create('plan_features', function (Blueprint $table) {
            $table->uuid('id')->primary();

            $table->foreignUuid('plan_id')
                ->constrained('plans')
                ->cascadeOnDelete();

            $table->string('feature', 60)
                ->comment('App\Enums\FeatureKey value: max_users, has_ai_exam_assistant, ...');

            $table->string('value', 100)
                ->comment("Booleano: '1'/'0'. Numérico: '0' = ilimitado.");

            $table->timestamps();

            // ── Constraints ───────────────────────────────────────────────────
            $table->unique(['plan_id', 'feature']);

            // ── Índices ───────────────────────────────────────────────────────
            // Consulta inversa: "quais planos oferecem X feature?"
            $table->index('feature');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('plan_features');
    }
};
