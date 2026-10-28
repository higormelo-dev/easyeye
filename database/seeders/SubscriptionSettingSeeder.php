<?php

namespace Database\Seeders;

use App\Models\SubscriptionSetting;
use App\Support\Fiscal\NfseSettings;
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

        // NFS-e do SaaS (Manager → Nota fiscal): só cria o que falta, nunca sobrescreve o que o dono já digitou.
        NfseSettings::ensureDefaults();
    }
}
