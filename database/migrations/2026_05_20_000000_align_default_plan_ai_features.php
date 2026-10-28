<?php

use App\Enums\FeatureKey;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

return new class() extends Migration {
    public function up(): void
    {
        $this->updatePlanDescription(
            slug: 'pro',
            description: 'Clínica com até 3 médicos que usa convênios TISS e quer produtividade com IA na redação de laudos.',
        );

        $this->syncFeatures('basico', [
            FeatureKey::HasAiExamAssistant->value  => '0',
            FeatureKey::HasAiReportDrafting->value => '0',
            FeatureKey::HasAiConsensus->value      => '0',
            FeatureKey::HasApiIntegrator->value    => '0',
            FeatureKey::ApiMonthlyExamSends->value => '0',
        ]);

        $this->syncFeatures('pro', [
            FeatureKey::HasAiExamAssistant->value  => '0',
            FeatureKey::HasAiReportDrafting->value => '1',
            FeatureKey::HasAiConsensus->value      => '0',
            FeatureKey::HasApiIntegrator->value    => '0',
            FeatureKey::ApiMonthlyExamSends->value => '0',
        ]);

        $this->syncFeatures('premium', [
            FeatureKey::HasAiExamAssistant->value  => '1',
            FeatureKey::HasAiReportDrafting->value => '1',
            FeatureKey::HasAiConsensus->value      => '1',
            FeatureKey::HasApiIntegrator->value    => '1',
            FeatureKey::ApiMonthlyExamSends->value => '0',
        ]);
    }

    public function down(): void
    {
        $this->updatePlanDescription(
            slug: 'pro',
            description: 'Clínica com até 3 médicos que usa convênios TISS e precisa de gestão avançada para laudos e faturamento.',
        );

        $this->syncFeatures('pro', [
            FeatureKey::HasAiExamAssistant->value  => '1',
            FeatureKey::HasAiReportDrafting->value => '0',
            FeatureKey::HasAiConsensus->value      => '0',
            FeatureKey::HasApiIntegrator->value    => '0',
            FeatureKey::ApiMonthlyExamSends->value => '0',
        ]);
    }

    private function updatePlanDescription(string $slug, string $description): void
    {
        DB::table('plans')
            ->where('slug', $slug)
            ->update([
                'description' => $description,
                'updated_at'  => now(),
            ]);
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
