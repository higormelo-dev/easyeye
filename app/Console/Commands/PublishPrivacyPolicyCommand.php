<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\{TermVersion, User};
use App\Services\TermsService;
use App\Support\Site\SiteLinks;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\{DB, File};

/**
 * Publica uma NOVA versão da Política de Privacidade a partir de
 * resources/legal/privacy-policy-{versão}.txt (o `.en.txt` vira a tradução de
 * cortesia da mesma versão). Versão publicada não se edita: o seeder só cria a
 * 1.0 e este comando nunca altera uma versão existente.
 *
 * Publicar desativa a anterior: /privacidade e o cadastro passam a mostrar a
 * nova. O painel NÃO pede novo aceite a quem já tem conta (o middleware
 * `terms.accepted` não está em nenhuma rota — .lgpd/gaps.md, G21), então
 * comunique a mudança aos usuários e às clínicas (política, seção 14).
 *
 * Texto que identifica o Encarregado (`{{encarregado_identity}}`) só é publicado
 * com LEGAL_ENCARREGADO_NAME configurado (LGPD art. 41; Res. CD/ANPD nº 18/2024):
 * publicar sem ele exigiria outra versão — e outro aceite de todos — logo depois.
 */
class PublishPrivacyPolicyCommand extends Command
{
    protected $signature = 'legal:publish-privacy-policy
        {version : Versão do arquivo (ex.: 1.1 → resources/legal/privacy-policy-1.1.txt)}
        {--by= : E-mail do usuário que publica (fica em term_versions.created_by)}
        {--summary= : Resumo das mudanças, em linguagem simples}';

    protected $description = 'Publica nova versão da Política de Privacidade (desativa a anterior; comunique a mudança aos usuários)';

    public function handle(TermsService $terms): int
    {
        $version = trim((string) $this->argument('version'));
        $path    = resource_path("legal/privacy-policy-{$version}.txt");

        if (preg_match('/^\d+\.\d+$/', $version) !== 1 || ! File::exists($path)) {
            return $this->refuse("Arquivo não encontrado: resources/legal/privacy-policy-{$version}.txt");
        }

        if (TermVersion::query()->where('type', 'privacy_policy')->where('version', $version)->exists()) {
            return $this->refuse("A versão {$version} já foi publicada e não pode ser alterada.");
        }

        $user = filled($this->option('by')) ? User::query()->where('email', (string) $this->option('by'))->first() : null;

        if ($user === null) {
            return $this->refuse('Informe --by com o e-mail de um usuário existente: quem publica fica registrado.');
        }

        $summary = trim((string) $this->option('summary'));

        if ($summary === '') {
            return $this->refuse('Informe --summary com o resumo das mudanças: é o que os usuários leem ao aceitar.');
        }

        $official = File::get($path);

        if (str_contains($official, '{{encarregado_identity}}') && blank(config('legal.encarregado_name'))) {
            return $this->refuse('Encarregado não designado: configure LEGAL_ENCARREGADO_NAME (LGPD art. 41; Res. CD/ANPD nº 18/2024) e rode config:cache antes de publicar.');
        }

        $content     = $this->fill($official, 'endereço: ');
        $enPath      = resource_path("legal/privacy-policy-{$version}.en.txt");
        $translation = File::exists($enPath) ? $this->fill(File::get($enPath), 'address: ') : null;

        foreach (array_filter([$content, $translation]) as $text) {
            if (str_contains($text, '{{')) {
                return $this->refuse('O texto tem marcador sem valor ({{…}}). Revise o arquivo antes de publicar.');
            }
        }

        $document = DB::transaction(function () use ($terms, $version, $content, $translation, $user, $summary): TermVersion {
            $document = $terms->publish('privacy_policy', $version, $content, now()->toDateString(), $user, $summary);

            if ($translation !== null) {
                $document->translations = ['en' => $translation];
                $document->save();
            }

            return $document;
        });

        $this->info("Política {$document->version} publicada, vigente desde {$document->effective_from->toDateString()}.");
        $this->warn('Comunique a mudança aos usuários e às clínicas: o painel não pede novo aceite a quem já tem conta.');

        return self::SUCCESS;
    }

    private function refuse(string $message): int
    {
        $this->error($message);

        return self::FAILURE;
    }

    /** Mesmos marcadores dos seeders da 1.0, mais o Encarregado e o atalho dos direitos. */
    private function fill(string $text, string $addressPrefix): string
    {
        $identity = [(string) config('legal.controller_name')];

        if (filled(config('legal.controller_cnpj'))) {
            $identity[] = 'CNPJ ' . config('legal.controller_cnpj');
        }

        if (filled(config('legal.controller_address'))) {
            $identity[] = $addressPrefix . config('legal.controller_address');
        }

        $contact = SiteLinks::contact();

        return trim(strtr($text, [
            '{{controller_identity}}'  => implode(', ', $identity),
            '{{privacy_email}}'        => (string) config('legal.privacy_email'),
            '{{privacy_phone}}'        => (string) config('legal.privacy_phone'),
            '{{rights_url}}'           => route('site.privacy') . '#direitos-titular',
            '{{encarregado_identity}}' => (string) config('legal.encarregado_name'),
            '{{encarregado_contact}}'  => implode(' · ', array_filter([$contact['encarregado_email'], $contact['encarregado_phone']])),
        ]));
    }
}
