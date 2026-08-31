<?php

use Database\Seeders\AiModelPriceSeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\{Cache, DB};

/**
 * Atualiza o catálogo de modelos/preços de IA em QUALQUER ambiente no deploy
 * (as correções feitas via tinker no dev não viajam com o código):
 *
 * 1. Upsert dos modelos ATUAIS com preço oficial (AiModelPriceSeeder é
 *    idempotente via updateOrCreate).
 * 2. Desativa modelos APOSENTADOS pelos provedores — Google: 1.5/2.0 (404) e
 *    família 2.5 (bloqueada p/ API keys novas); Anthropic: claude-3-* (retired).
 *    Linhas ficam inativas (histórico de liquidação preservado).
 * 3. Remove escolha de modelo do painel (ai.provider_models) que aponte para
 *    modelo aposentado — o efetivo volta ao fallback do env. Sem isso o run/
 *    teste de conexão continua chamando um modelo morto (404 visto no teste).
 */
return new class extends Migration {
    private const RETIRED = [
        'gemini-1.5-flash',
        'gemini-1.5-pro',
        'gemini-2.0-flash',
        'gemini-2.5-flash',
        'gemini-2.5-flash-lite',
        'gemini-2.5-pro',
        'claude-3-5-sonnet-20241022',
        'claude-3-5-haiku-20241022',
        'claude-3-opus-20240229',
    ];

    public function up(): void
    {
        // Pula APENAS sob PHPUnit/Pest (a suíte controla o catálogo por teste).
        // NÃO usar environment('testing'): o servidor de homologação roda com
        // APP_ENV=testing e a migration ficaria como "Ran" sem fazer nada.
        // Detecção: TestCase JÁ CARREGADA (autoload=false) — verdadeiro só
        // dentro do runner de testes, nunca num artisan migrate de servidor.
        if (class_exists(\PHPUnit\Framework\TestCase::class, false)) {
            return;
        }

        (new AiModelPriceSeeder())->run();

        DB::table('ai_model_prices')
            ->whereIn('model', self::RETIRED)
            ->where('active', true)
            ->update(['active' => false, 'effective_until' => now(), 'updated_at' => now()]);

        // Escolhas do painel apontando para modelo aposentado → remove a
        // entrada (modelo efetivo cai no fallback do env/config).
        $row = DB::table('system_settings')->where('key', 'ai.provider_models')->first();

        if ($row !== null) {
            $models = json_decode((string) $row->value, true);

            if (is_array($models)) {
                $clean = array_filter($models, fn ($m) => ! in_array($m, self::RETIRED, true));

                if ($clean !== $models) {
                    DB::table('system_settings')->where('key', 'ai.provider_models')
                        ->update(['value' => json_encode($clean), 'updated_at' => now()]);
                }
            }
        }

        Cache::forget('subscription_setting:ai.provider_models');
        Cache::forget('subscription_setting:ai.enabled_providers');
    }

    public function down(): void
    {
        // Sem reversão: reativar modelos aposentados pelos provedores não faz
        // sentido e o histórico de preços não deve ser apagado.
    }
};
