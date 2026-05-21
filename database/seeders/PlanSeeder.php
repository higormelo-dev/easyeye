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
                'description'   => 'Consultório individual que quer sair do papel e do Excel. Prontuário digital, agenda e gestão completa para 1 médico.',
                'price'         => 299.90,
                'billing_cycle' => BillingCycle::Monthly,
                'active'        => true,
                'sort_order'    => 1,
                'features'      => [
                    FeatureKey::MaxUsers->value            => '3',
                    FeatureKey::MaxPatients->value         => '500',
                    FeatureKey::MaxDoctors->value          => '1',
                    FeatureKey::MaxStorageGB->value        => '2',
                    FeatureKey::HasAiExamAssistant->value  => '0',
                    FeatureKey::HasAiReportDrafting->value => '0',
                    FeatureKey::HasAiConsensus->value      => '0',
                    FeatureKey::HasApiIntegrator->value    => '0',
                    FeatureKey::AiMonthlyCredits->value    => '0',
                    FeatureKey::ApiMonthlyExamSends->value => '0',
                ],
            ],
            [
                'name'          => 'Pro',
                'slug'          => 'pro',
                'description'   => 'Clínica com até 3 médicos que usa convênios TISS e quer produtividade com IA na redação de laudos.',
                'price'         => 899.90,
                'billing_cycle' => BillingCycle::Monthly,
                'active'        => true,
                'sort_order'    => 2,
                'features'      => [
                    FeatureKey::MaxUsers->value            => '10',
                    FeatureKey::MaxPatients->value         => '5000',
                    FeatureKey::MaxDoctors->value          => '3',
                    FeatureKey::MaxStorageGB->value        => '5',
                    FeatureKey::HasAiExamAssistant->value  => '0',
                    FeatureKey::HasAiReportDrafting->value => '1',
                    FeatureKey::HasAiConsensus->value      => '0',
                    FeatureKey::HasApiIntegrator->value    => '0',
                    FeatureKey::AiMonthlyCredits->value    => '30',  // degustação — excedente R$ 2,50/crédito
                    FeatureKey::ApiMonthlyExamSends->value => '0',
                ],
            ],
            [
                'name'          => 'Premium',
                'slug'          => 'premium',
                'description'   => 'Policlínicas com até 10 médicos que precisam de IA, automação de faturamento TISS e escala operacional.',
                'price'         => 1799.90,
                'billing_cycle' => BillingCycle::Monthly,
                'active'        => true,
                'sort_order'    => 3,
                'features'      => [
                    FeatureKey::MaxUsers->value            => '0', // ilimitado
                    FeatureKey::MaxPatients->value         => '0', // ilimitado
                    FeatureKey::MaxDoctors->value          => '10',
                    FeatureKey::MaxStorageGB->value        => '10',
                    FeatureKey::HasAiExamAssistant->value  => '1',
                    FeatureKey::HasAiReportDrafting->value => '1',
                    FeatureKey::HasAiConsensus->value      => '1',
                    FeatureKey::HasApiIntegrator->value    => '1',
                    FeatureKey::AiMonthlyCredits->value    => '80',  // franquia — excedente R$ 2,00/crédito
                    FeatureKey::ApiMonthlyExamSends->value => '0',   // ilimitado → cap interno 1000
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
                    ['value' => $value],
                );
            }
        }
    }
}
