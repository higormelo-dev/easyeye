<?php

use App\Enums\FeatureKey;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Módulo cirúrgico (Fase 4) — recurso de plano superior `has_surgery`.
 *
 * Preenche a chave em TODO plano existente: Premium liga ('1'); os demais
 * ficam explicitamente desligados ('0'). Plano sem linha já nega (feature
 * ausente = não permitida), mas a linha explícita faz o plano aparecer com o
 * recurso no editor do manager. Idempotente e sem sobrescrever uma escolha já
 * feita: só cria a linha quando ela não existe.
 */
return new class() extends Migration {
    public function up(): void
    {
        $feature = FeatureKey::HasSurgery->value;

        foreach (DB::table('plans')->select('id', 'slug')->get() as $plan) {
            $exists = DB::table('plan_features')
                ->where('plan_id', $plan->id)
                ->where('feature', $feature)
                ->exists();

            if ($exists) {
                continue;
            }

            DB::table('plan_features')->insert([
                'id'         => (string) Str::uuid(),
                'plan_id'    => $plan->id,
                'feature'    => $feature,
                'value'      => $plan->slug === 'premium' ? '1' : '0',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        DB::table('plan_features')->where('feature', FeatureKey::HasSurgery->value)->delete();
    }
};
