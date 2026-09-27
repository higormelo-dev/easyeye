<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\TermVersion;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\File;

class TermsOfUseSeeder extends Seeder
{
    public function run(): void
    {
        // Preserva documentos históricos, inativos ou com publicação futura.
        if (TermVersion::query()->where('type', 'terms_of_service')->exists()) {
            return;
        }

        $identity = [(string) config('legal.controller_name')];

        if (filled(config('legal.controller_cnpj'))) {
            $identity[] = 'CNPJ ' . config('legal.controller_cnpj');
        }

        if (filled(config('legal.controller_address'))) {
            $identity[] = 'com sede em ' . config('legal.controller_address');
        }

        $content = strtr(File::get(resource_path('legal/terms-of-use-1.0.txt')), [
            '{{provider_identity}}' => implode(', ', $identity),
            '{{terms_email}}'       => (string) config('legal.terms_email'),
            '{{terms_phone}}'       => (string) config('legal.terms_phone'),
            '{{support_email}}'     => (string) config('mail.support_address'),
        ]);

        TermVersion::query()->firstOrCreate(
            ['type' => 'terms_of_service', 'version' => '1.0'],
            [
                'content'        => trim($content),
                'summary'        => 'Termos iniciais: licença, planos, créditos de IA, responsabilidades, cancelamento e exportação de dados.',
                'effective_from' => '2026-09-27',
                'active'         => true,
            ],
        );
    }
}
