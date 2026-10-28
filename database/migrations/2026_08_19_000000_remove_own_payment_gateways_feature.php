<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Remove a feature `has_own_payment_gateways` de todos os planos — a
 * funcionalidade "gateways de pagamento próprios da clínica" foi retirada do
 * produto (todas as clínicas usam o gateway centralizado do SaaS). Some da
 * lista de features dos planos na landing page e do editor de planos do
 * manager (ambos leem plan_features).
 *
 * Os gateways do PRÓPRIO SaaS (manager.gateways.*) não são afetados, e as
 * credenciais tenant já cadastradas em gateway_credentials ficam intactas
 * como histórico (nenhuma rota as usa mais).
 */
return new class() extends Migration {
    public function up(): void
    {
        DB::table('plan_features')
            ->where('feature', 'has_own_payment_gateways')
            ->delete();
    }

    public function down(): void
    {
        // Sem rollback de dados: a feature saiu do produto (o case do enum
        // FeatureKey nem existe mais) — recriar as linhas só reintroduziria
        // uma chave órfã.
    }
};
