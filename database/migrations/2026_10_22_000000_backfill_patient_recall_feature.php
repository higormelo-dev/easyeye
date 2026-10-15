<?php

use App\Enums\FeatureKey;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Cria a feature `has_patient_recall` (retorno de pacientes crônicos — Fase 6)
 * nos planos que já existem: ligada nos planos Pro e Premium, desligada nos
 * demais (inclusive planos criados à mão no Manager, que o dono ajusta na tela
 * de planos). Nunca sobrescreve um valor que já esteja gravado — rodar de novo,
 * ou o PlanSeeder ter chegado antes, não desfaz escolha de ninguém.
 */
return new class() extends Migration {
    /** Planos que recebem a feature por padrão. */
    private const ENABLED_SLUGS = ['pro', 'premium'];

    public function up(): void
    {
        $key = FeatureKey::HasPatientRecall->value;

        foreach (DB::table('plans')->select(['id', 'slug'])->get() as $plan) {
            $exists = DB::table('plan_features')
                ->where('plan_id', $plan->id)
                ->where('feature', $key)
                ->exists();

            if ($exists) {
                continue;
            }

            DB::table('plan_features')->insert([
                'id'         => (string) Str::uuid(),
                'plan_id'    => $plan->id,
                'feature'    => $key,
                'value'      => in_array($plan->slug, self::ENABLED_SLUGS, true) ? '1' : '0',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        DB::table('plan_features')
            ->where('feature', FeatureKey::HasPatientRecall->value)
            ->delete();
    }
};
