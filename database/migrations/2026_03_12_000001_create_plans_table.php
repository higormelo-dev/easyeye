<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class() extends Migration {
    public function up(): void
    {
        Schema::create('plans', function (Blueprint $table) {
            $table->uuid('id')->primary();

            $table->string('name', 100);
            $table->string('slug', 100)->unique();
            $table->text('description')->nullable();

            // Preço e cobrança — App\Enums\BillingCycle: monthly|yearly|lifetime
            $table->decimal('price', 10, 2)->default(0);
            $table->string('billing_cycle', 20)->default('monthly');

            // Trial por plano (NULL = usa system_settings.trial_days)
            $table->unsignedSmallInteger('trial_days')->nullable()
                ->comment('Override do trial para este plano. NULL = usa system_settings.');

            // Visibilidade
            $table->boolean('active')->default(true);
            $table->boolean('is_featured')->default(false)
                ->comment('Exibe badge "Mais popular" na tela de planos/registro.');
            $table->unsignedSmallInteger('sort_order')->default(0);

            $table->softDeletes();
            $table->timestamps();

            // ── Índices ───────────────────────────────────────────────────────
            $table->index(['active', 'sort_order']);
            $table->index('is_featured');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('plans');
    }
};
