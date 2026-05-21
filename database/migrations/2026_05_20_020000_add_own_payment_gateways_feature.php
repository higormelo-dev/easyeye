<?php

use App\Enums\FeatureKey;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Adiciona a feature `has_own_payment_gateways` aos planos existentes.
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
            FeatureKey::HasOwnPaymentGateways->value => '0',
        ]);
        $this->syncFeatures('pro', [
            FeatureKey::HasOwnPaymentGateways->value => '0',
        ]);
        $this->syncFeatures('premium', [
            FeatureKey::HasOwnPaymentGateways->value => '0',
        ]);
    }

    public function down(): void
    {
        DB::table('plan_features')
            ->where('feature', FeatureKey::HasOwnPaymentGateways->value)
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
