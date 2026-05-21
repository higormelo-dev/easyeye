<?php

use App\Enums\FeatureKey;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

return new class() extends Migration {
    public function up(): void
    {
        $now = now();

        $planIds = DB::table('plans')->pluck('id');

        foreach ($planIds as $planId) {
            $alreadyExists = DB::table('plan_features')
                ->where('plan_id', $planId)
                ->where('feature', FeatureKey::HasAiConsensus->value)
                ->exists();

            if ($alreadyExists) {
                continue;
            }

            $hasReportDrafting = DB::table('plan_features')
                ->where('plan_id', $planId)
                ->where('feature', FeatureKey::HasAiReportDrafting->value)
                ->value('value') === '1';

            $hasExamAssistant = DB::table('plan_features')
                ->where('plan_id', $planId)
                ->where('feature', FeatureKey::HasAiExamAssistant->value)
                ->value('value') === '1';

            DB::table('plan_features')->insert([
                'id'         => (string) Str::uuid(),
                'plan_id'    => $planId,
                'feature'    => FeatureKey::HasAiConsensus->value,
                'value'      => $hasReportDrafting && $hasExamAssistant ? '1' : '0',
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        DB::table('plan_features')
            ->where('feature', FeatureKey::HasAiConsensus->value)
            ->delete();
    }
};
