<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tabela renomeada de subscription_settings → system_settings.
 *
 * Serve como repositório central de configurações do sistema, não apenas
 * de subscription. Grupos sugeridos:
 *   subscription : trial_days, grace_period_days
 *   billing      : currency, tax_rate
 *   ai           : default_ai_model, ai_cost_per_credit
 *   mail         : support_email, noreply_email
 *   general      : timezone, maintenance_mode
 *
 * ATENÇÃO — code changes:
 *   - Renomear SubscriptionSetting model para SystemSetting
 *   - Atualizar $table = 'system_settings' no model
 *   - Atualizar SubscriptionSettingSeeder com campos type, group, label
 */
return new class () extends Migration {
    public function up(): void
    {
        Schema::create('system_settings', function (Blueprint $table) {
            $table->string('key', 100)->primary()
                ->comment('Snake_case. Ex: trial_days, grace_period_days.');

            $table->text('value')
                ->comment('Sempre string; o cast correto é feito pelo campo type.');

            $table->string('type', 20)->default('string')
                ->comment('string | integer | boolean | json | float');

            $table->string('group', 50)->default('general')
                ->comment('subscription | billing | ai | mail | general');

            $table->string('label', 255)->nullable()
                ->comment('Label legível para tela de admin.');

            $table->text('description')->nullable();

            $table->boolean('is_public')->default(false)
                ->comment('true = pode ser exposta ao frontend via API pública.');

            $table->timestamps();

            // ── Índices ───────────────────────────────────────────────────────
            $table->index('group');
            $table->index(['group', 'is_public']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('system_settings');
    }
};
