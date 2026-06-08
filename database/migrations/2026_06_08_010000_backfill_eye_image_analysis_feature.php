<?php

use App\Enums\FeatureKey;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Habilita a feature `has_ai_eye_image_analysis` nos planos que já possuem IA
 * (assistente de exame OU redação de laudo). Mantém a feature utilizável de
 * imediato sem precisar reconfigurar planos manualmente.
 */
return new class() extends Migration {
    public function up(): void
    {
        $key = FeatureKey::HasAiEyeImageAnalysis->value;

        $plans = DB::table('plans')->pluck('id');

        foreach ($plans as $planId) {
            $hasAi = DB::table('plan_features')
                ->where('plan_id', $planId)
                ->whereIn('feature', [
                    FeatureKey::HasAiExamAssistant->value,
                    FeatureKey::HasAiReportDrafting->value,
                ])
                ->where('value', '1')
                ->exists();

            $value = $hasAi ? '1' : '0';

            $exists = DB::table('plan_features')
                ->where('plan_id', $planId)
                ->where('feature', $key)
                ->exists();

            if ($exists) {
                DB::table('plan_features')
                    ->where('plan_id', $planId)
                    ->where('feature', $key)
                    ->update(['value' => $value, 'updated_at' => now()]);

                continue;
            }

            DB::table('plan_features')->insert([
                'id'         => (string) Str::uuid(),
                'plan_id'    => $planId,
                'feature'    => $key,
                'value'      => $value,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        DB::table('plan_features')
            ->where('feature', FeatureKey::HasAiEyeImageAnalysis->value)
            ->delete();
    }
};
