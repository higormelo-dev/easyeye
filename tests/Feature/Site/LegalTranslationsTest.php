<?php

declare(strict_types=1);

use App\Models\TermVersion;
use Database\Seeders\{LegalDocumentTranslationsSeeder, PrivacyPolicySeeder, TermsOfUseSeeder};
use Inertia\Testing\AssertableInertia as Assert;

/*
 * /privacidade e /termos em mais de um idioma.
 *
 * O texto oficial é o em português (term_versions.content): é ele que vale e
 * que os usuários aceitam no painel. Em inglês a página mostra a tradução de
 * cortesia da MESMA versão (translations.en), avisando que o português
 * prevalece; sem tradução, mostra o original e avisa.
 */

function legalTranslatedTerm(string $type, array $overrides = []): TermVersion
{
    return TermVersion::query()->create([
        'type'           => $type,
        'version'        => '1.0',
        'content'        => "1. Quem somos\n\nTexto oficial.",
        'translations'   => ['en' => "1. Who we are\n\nCourtesy text."],
        'effective_from' => now()->subDay()->toDateString(),
        'active'         => true,
        ...$overrides,
    ]);
}

/** Linha de documento sem a tradução: o que o seeder não pode alterar. */
function legalOfficialSnapshot(): array
{
    return TermVersion::query()->orderBy('type')->get()
        ->map(fn (TermVersion $document) => [
            $document->id,
            $document->type,
            $document->version,
            $document->content,
            $document->effective_from?->toDateString(),
            $document->active,
        ])->all();
}

describe('idioma do documento legal', function (): void {
    it('em português mostra o texto oficial, sem aviso de idioma', function (string $url, string $type): void {
        legalTranslatedTerm($type);

        $this->withSession(['locale' => 'pt_BR'])->get($url)
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('document.content', "1. Quem somos\n\nTexto oficial.")
                ->where('document.contentLang', 'pt-BR')
                ->where('document.isTranslation', false)
                ->where('document.isOriginal', false));
    })->with([['/privacidade', 'privacy_policy'], ['/termos', 'terms_of_service']]);

    it('em inglês mostra a tradução de cortesia com link para o original', function (string $url, string $type): void {
        legalTranslatedTerm($type);

        $this->withSession(['locale' => 'en'])->get($url)
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('document.content', "1. Who we are\n\nCourtesy text.")
                ->where('document.contentLang', 'en')
                ->where('document.isTranslation', true)
                ->where('document.isOriginal', false)
                ->where('document.originalUrl', fn ($url) => str_ends_with($url, '?original=1'))
                ->where('t.legal.translation_notice', fn ($text) => str_contains($text, 'Portuguese version prevails')));
    })->with([['/privacidade', 'privacy_policy'], ['/termos', 'terms_of_service']]);

    it('?original=1 mostra o original em português mantendo a página em inglês', function (): void {
        legalTranslatedTerm('privacy_policy');

        $this->withSession(['locale' => 'en'])->get('/privacidade?original=1')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('document.content', "1. Quem somos\n\nTexto oficial.")
                ->where('document.contentLang', 'pt-BR')
                ->where('document.isOriginal', true)
                ->where('document.hasTranslation', true)
                ->where('t.legal.read_translation', 'Back to the translation'));
    });

    it('sem tradução, em inglês mostra o original e avisa que só existe em português', function (): void {
        legalTranslatedTerm('terms_of_service', ['translations' => null]);

        $this->withSession(['locale' => 'en'])->get('/termos')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('document.content', "1. Quem somos\n\nTexto oficial.")
                ->where('document.contentLang', 'pt-BR')
                ->where('document.isTranslation', false)
                ->where('document.isOriginal', true)
                ->where('document.hasTranslation', false));
    });

    it('a tradução não cria outra versão: o aceite continua apontando para o mesmo registro', function (): void {
        $document = legalTranslatedTerm('privacy_policy');

        expect(TermVersion::currentFor('privacy_policy')->id)->toBe($document->id)
            ->and(TermVersion::query()->where('type', 'privacy_policy')->count())->toBe(1);
    });
});

describe('LegalDocumentTranslationsSeeder', function (): void {
    beforeEach(function (): void {
        $this->seed([PrivacyPolicySeeder::class, TermsOfUseSeeder::class]);
    });

    it('adiciona o inglês às versões publicadas sem mexer no texto oficial, datas ou estado', function (): void {
        $before = legalOfficialSnapshot();

        $this->seed(LegalDocumentTranslationsSeeder::class);

        expect(legalOfficialSnapshot())->toBe($before);

        TermVersion::query()->get()->each(function (TermVersion $document): void {
            $english = $document->translations['en'] ?? '';
            expect($english)->not->toBe('')
                ->and($english)->not->toContain('{{')
                ->and($english)->toContain((string) config('legal.controller_name'));
        });
    });

    it('a tradução tem a mesma estrutura do oficial (mesmas seções e listas)', function (): void {
        $this->seed(LegalDocumentTranslationsSeeder::class);

        $shape = function (string $text): array {
            $blocks = array_values(array_filter(array_map('trim', preg_split('/\n\s*\n/', str_replace("\r\n", "\n", $text)))));

            return [
                'blocks'   => count($blocks),
                'headings' => array_values(array_map(fn ($block) => (int) $block, array_filter($blocks, fn ($block) => preg_match('/^\d+\.\s+[^\n]+$/', $block) === 1))),
                'lists'    => count(array_filter($blocks, fn ($block) => collect(explode("\n", $block))->every(fn ($line) => preg_match('/^-\s+/', $line) === 1))),
            ];
        };

        TermVersion::query()->get()->each(function (TermVersion $document) use ($shape): void {
            expect($shape($document->translations['en']))->toBe($shape($document->content));
        });
    });

    it('reexecutar não muda nada e não sobrescreve tradução publicada', function (): void {
        $this->seed(LegalDocumentTranslationsSeeder::class);
        $policy = TermVersion::query()->where('type', 'privacy_policy')->first();
        $policy->update(['translations' => ['en' => 'Tradução revisada publicada.']]);

        $this->seed(LegalDocumentTranslationsSeeder::class);

        expect($policy->fresh()->translations)->toBe(['en' => 'Tradução revisada publicada.']);
    });

    it('sem a versão publicada, não cria nada', function (): void {
        TermVersion::query()->delete();

        $this->seed(LegalDocumentTranslationsSeeder::class);

        expect(TermVersion::query()->count())->toBe(0);
    });
});
