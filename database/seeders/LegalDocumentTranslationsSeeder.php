<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\TermVersion;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\File;

/**
 * Traduções de cortesia (inglês) das versões publicadas da Política de
 * Privacidade e dos Termos de Uso.
 *
 * Só completa `translations` de uma versão que já existe e ainda não tem o
 * idioma: nunca altera o texto oficial (`content`), datas, estado ou ids, nem
 * sobrescreve uma tradução já publicada. Reexecutar não muda nada.
 */
class LegalDocumentTranslationsSeeder extends Seeder
{
    public function run(): void
    {
        $this->translate('privacy_policy', '1.0', 'en', 'legal/privacy-policy-1.0.en.txt', [
            '{{controller_identity}}' => $this->identity('address: '),
            '{{privacy_email}}'       => (string) config('legal.privacy_email'),
            '{{privacy_phone}}'       => (string) config('legal.privacy_phone'),
        ]);

        $this->translate('terms_of_service', '1.0', 'en', 'legal/terms-of-use-1.0.en.txt', [
            '{{provider_identity}}' => $this->identity('headquartered at '),
            '{{terms_email}}'       => (string) config('legal.terms_email'),
            '{{terms_phone}}'       => (string) config('legal.terms_phone'),
            '{{support_email}}'     => (string) config('mail.support_address'),
        ]);
    }

    /** @param array<string, string> $placeholders */
    private function translate(string $type, string $version, string $locale, string $file, array $placeholders): void
    {
        $document = TermVersion::query()->where('type', $type)->where('version', $version)->first();
        $path     = resource_path($file);

        if (! $document || filled($document->translations[$locale] ?? null) || ! File::exists($path)) {
            return;
        }

        $document->translations = [
            ...($document->translations ?? []),
            $locale => trim(strtr(File::get($path), $placeholders)),
        ];
        $document->save();
    }

    /** Mesma identificação dos seeders em português, com os conectores em inglês. */
    private function identity(string $addressPrefix): string
    {
        $identity = [(string) config('legal.controller_name')];

        if (filled(config('legal.controller_cnpj'))) {
            $identity[] = 'CNPJ ' . config('legal.controller_cnpj');
        }

        if (filled(config('legal.controller_address'))) {
            $identity[] = $addressPrefix . config('legal.controller_address');
        }

        return implode(', ', $identity);
    }
}
