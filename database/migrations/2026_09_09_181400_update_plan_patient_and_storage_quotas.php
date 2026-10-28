<?php

use App\Enums\FeatureKey;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

return new class() extends Migration {
    public function up(): void
    {
        $this->syncFeatures('basico', [
            FeatureKey::MaxPatients->value  => '2000',
            FeatureKey::MaxStorageGB->value => '10',
        ]);

        $this->syncFeatures('pro', [
            FeatureKey::MaxPatients->value  => '10000',
            FeatureKey::MaxStorageGB->value => '50',
        ]);

        $this->syncFeatures('premium', [
            FeatureKey::MaxPatients->value  => '0', // 0 = ilimitado
            FeatureKey::MaxStorageGB->value => '200',
        ]);
    }

    public function down(): void
    {
        $this->syncFeatures('basico', [
            FeatureKey::MaxPatients->value  => '500',
            FeatureKey::MaxStorageGB->value => '2',
        ]);

        $this->syncFeatures('pro', [
            FeatureKey::MaxPatients->value  => '5000',
            FeatureKey::MaxStorageGB->value => '5',
        ]);

        $this->syncFeatures('premium', [
            FeatureKey::MaxPatients->value  => '0',
            FeatureKey::MaxStorageGB->value => '10',
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
