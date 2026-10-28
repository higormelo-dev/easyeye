<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\TermVersion;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\File;

class PrivacyPolicySeeder extends Seeder
{
    public function run(): void
    {
        // Documentos publicados são históricos: não sobrescrever, reativar
        // nem antecipar uma política já cadastrada (inclusive futura).
        if (TermVersion::query()->where('type', 'privacy_policy')->exists()) {
            return;
        }

        $identity = [(string) config('legal.controller_name')];

        if (filled(config('legal.controller_cnpj'))) {
            $identity[] = 'CNPJ ' . config('legal.controller_cnpj');
        }

        if (filled(config('legal.controller_address'))) {
            $identity[] = 'endereço: ' . config('legal.controller_address');
        }

        $content = strtr(File::get(resource_path('legal/privacy-policy-1.0.txt')), [
            '{{controller_identity}}' => implode(', ', $identity),
            '{{privacy_email}}'       => (string) config('legal.privacy_email'),
            '{{privacy_phone}}'       => (string) config('legal.privacy_phone'),
        ]);

        TermVersion::query()->firstOrCreate(
            ['type' => 'privacy_policy', 'version' => '1.0'],
            [
                'content'        => trim($content),
                'summary'        => 'Política inicial: site, plataforma, dados de saúde, IA, compartilhamento e direitos dos titulares.',
                'effective_from' => '2026-09-27',
                'active'         => true,
            ],
        );
    }
}
