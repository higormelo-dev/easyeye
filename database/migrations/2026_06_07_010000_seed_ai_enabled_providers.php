<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Define o conjunto inicial de provedores de IA habilitados (MVP: apenas
 * Gemini). A partir daqui o dono do SaaS controla quais/quantos provedores o
 * sistema usa pela área administrativa (system_settings -> ai.enabled_providers).
 */
return new class() extends Migration {
    public function up(): void
    {
        // Em ambiente de testes não semeamos o default: a suíte de IA existente
        // assume o conjunto de 3 provedores (fallback de config). Os testes que
        // exercitam o modo "Gemini-only" definem o setting explicitamente.
        if (app()->environment('testing')) {
            return;
        }

        // Não sobrescreve se já existir (idempotente / preserva ajustes do admin).
        $exists = DB::table('system_settings')->where('key', 'ai.enabled_providers')->exists();

        if (! $exists) {
            DB::table('system_settings')->insert([
                'key'         => 'ai.enabled_providers',
                'value'       => json_encode(['gemini']),
                'type'        => 'json',
                'group'       => 'ai',
                'label'       => 'Provedores de IA habilitados',
                'description' => 'Lista ordenada (prioridade) dos provedores de IA que o sistema pode usar.',
                'is_public'   => false,
                'created_at'  => now(),
                'updated_at'  => now(),
            ]);
        }
    }

    public function down(): void
    {
        DB::table('system_settings')->where('key', 'ai.enabled_providers')->delete();
    }
};
