<?php

namespace Database\Seeders;

use App\Models\SubscriptionSetting;
use Illuminate\Database\Seeder;

class SubscriptionSettingSeeder extends Seeder
{
    public function run(): void
    {
        $settings = [
            [
                'key'         => 'trial_days',
                'value'       => '7',
                'description' => 'Duração do período de trial em dias',
            ],
            [
                'key'         => 'grace_period_days',
                'value'       => '3',
                'description' => 'Dias de acesso após expiração da assinatura (período de graça)',
            ],
            [
                'key'         => 'ai.enabled_providers',
                'value'       => json_encode(['gemini']),
                'type'        => 'json',
                'group'       => 'ai',
                'label'       => 'Provedores de IA habilitados',
                'description' => 'Lista ordenada (prioridade) dos provedores de IA que o sistema pode usar.',
            ],
        ];

        foreach ($settings as $setting) {
            SubscriptionSetting::updateOrCreate(
                ['key' => $setting['key']],
                $setting,
            );
        }
    }
}
