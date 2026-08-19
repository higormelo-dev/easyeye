<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Adiciona a feature `has_own_payment_gateways` aos planos existentes.
 *
 * NOTA (2026-08-19): a feature foi REMOVIDA do produto (clínicas não têm mais
 * gateways próprios) — o case do enum FeatureKey não existe mais, por isso
 * esta migration usa a string literal (histórico precisa rodar em instalação
 * nova). A migration 2026_08_19_000000_remove_own_payment_gateways_feature
 * apaga as linhas logo em seguida.
 *
 * Padrão: desabilitado em todos os planos. A configuração de gateways próprios
 * (Asaas, MP, etc.) é uma necessidade pontual — a clínica usa o gateway
 * centralizado do SaaS por padrão. O Manager SaaS habilita case-by-case quando
 * a clínica precisar.
 *
 * Para habilitar manualmente:
 *   docker exec easyeye_app php artisan tinker --execute="
 *     DB::table('plan_features')->updateOrInsert(
 *       ['plan_id' => '<plan_id>', 'feature' => 'has_own_payment_gateways'],
 *       ['value' => '1', 'updated_at' => now(), 'created_at' => now(), 'id' => (string) Str::uuid()]
 *     );
 *   "
 */
return new class() extends Migration {
    public function up(): void
    {
        // Default off em todos os planos — clínica usa gateway centralizado do SaaS.
        $this->syncFeatures('basico', [
            'has_own_payment_gateways' => '0',
        ]);
        $this->syncFeatures('pro', [
            'has_own_payment_gateways' => '0',
        ]);
        $this->syncFeatures('premium', [
            'has_own_payment_gateways' => '0',
        ]);
    }

    public function down(): void
    {
        DB::table('plan_features')
            ->where('feature', 'has_own_payment_gateways')
            ->delete();
    }

    /**
     * @param array<string, string> $features
     */
    private function syncFeatures(string $planSlug, array $features): void
    {
        $planId = DB::table('plans')->where('slug', $planSlug)->value('id');

        if (! $planId) {
            return;
        }

        foreach ($features as $feature => $value) {
            $exists = DB::table('plan_features')
                ->where('plan_id', $planId)
                ->where('feature', $feature)
                ->exists();

            if ($exists) {
                DB::table('plan_features')
                    ->where('plan_id', $planId)
                    ->where('feature', $feature)
                    ->update([
                        'value'      => $value,
                        'updated_at' => now(),
                    ]);

                continue;
            }

            DB::table('plan_features')->insert([
                'id'         => (string) Str::uuid(),
                'plan_id'    => $planId,
                'feature'    => $feature,
                'value'      => $value,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }
};
