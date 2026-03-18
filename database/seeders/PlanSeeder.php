<?php

namespace Database\Seeders;

use App\Enums\{BillingCycle, FeatureKey};
use App\Models\{Plan, PlanFeature};
use Illuminate\Database\Seeder;

class PlanSeeder extends Seeder
{
    public function run(): void
    {
        $plans = [
            [
                'name'          => 'Básico',
                'slug'          => 'basico',
                'description'   => 'Ideal para clínicas pequenas que estão começando.',
                'price'         => 99.90,
                'billing_cycle' => BillingCycle::Monthly,
                'active'        => true,
                'sort_order'    => 1,
                'features'      => [
                    FeatureKey::MaxUsers->value            => '3',
                    FeatureKey::MaxPatients->value         => '200',
                    FeatureKey::MaxDoctors->value          => '2',
                    FeatureKey::HasAiExamAssistant->value  => '0',
                    FeatureKey::HasAiReportDrafting->value => '0',
                    FeatureKey::HasApiIntegrator->value    => '0',
                    FeatureKey::AiMonthlyCredits->value    => '0',
                    FeatureKey::ApiMonthlyExamSends->value => '0',
                ],
            ],
            [
                'name'          => 'Pro',
                'slug'          => 'pro',
                'description'   => 'Para clínicas em crescimento com necessidade de IA e integradores.',
                'price'         => 249.90,
                'billing_cycle' => BillingCycle::Monthly,
                'active'        => true,
                'sort_order'    => 2,
                'features'      => [
                    FeatureKey::MaxUsers->value            => '10',
                    FeatureKey::MaxPatients->value         => '2000',
                    FeatureKey::MaxDoctors->value          => '5',
                    FeatureKey::HasAiExamAssistant->value  => '1',
                    FeatureKey::HasAiReportDrafting->value => '0',
                    FeatureKey::HasApiIntegrator->value    => '1',
                    FeatureKey::AiMonthlyCredits->value    => '50',
                    FeatureKey::ApiMonthlyExamSends->value => '100',
                ],
            ],
            [
                'name'          => 'Premium',
                'slug'          => 'premium',
                'description'   => 'Recursos ilimitados com IA completa para grandes clínicas.',
                'price'         => 499.90,
                'billing_cycle' => BillingCycle::Monthly,
                'active'        => true,
                'sort_order'    => 3,
                'features'      => [
                    FeatureKey::MaxUsers->value            => '0', // ilimitado
                    FeatureKey::MaxPatients->value         => '0', // ilimitado
                    FeatureKey::MaxDoctors->value          => '0', // ilimitado
                    FeatureKey::HasAiExamAssistant->value  => '1',
                    FeatureKey::HasAiReportDrafting->value => '1',
                    FeatureKey::HasApiIntegrator->value    => '1',
                    FeatureKey::AiMonthlyCredits->value    => '500',
                    FeatureKey::ApiMonthlyExamSends->value => '0', // ilimitado → cap interno 1000
                ],
            ],
        ];

        foreach ($plans as $planData) {
            $features = $planData['features'];
            unset($planData['features']);

            $plan = Plan::updateOrCreate(['slug' => $planData['slug']], $planData);

            foreach ($features as $key => $value) {
                PlanFeature::updateOrCreate(
                    ['plan_id' => $plan->id, 'feature' => $key],
                    ['value' => $value]
                );
            }
        }
    }
}
