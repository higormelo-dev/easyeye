<?php

declare(strict_types=1);

use App\Models\TermVersion;
use Inertia\Testing\AssertableInertia as Assert;

/*
 * Rodapé e páginas legais do site.
 *
 * Antes: os 10 links do rodapé (Ajuda, Status, API, Sobre, Blog, Parceiros,
 * Carreiras, Privacidade, Termos, LGPD) eram URLs fixas que respondiam 404 —
 * inclusive a Política de Privacidade citada no consentimento do formulário
 * de contato (LGPD). E a página usava 3 e-mails diferentes, um deles fixo no
 * Vue. Agora: /privacidade e /termos mostram a versão vigente dos documentos
 * oficiais (term_versions); os demais links só aparecem quando a rota existe;
 * e-mails vêm da config.
 */

function siteLegalTerm(string $type, string $version, array $overrides = []): TermVersion
{
    return TermVersion::query()->create([
        'type'           => $type,
        'version'        => $version,
        'content'        => "Primeiro parágrafo da versão {$version}.\n\nSegundo parágrafo.",
        'effective_from' => now()->subDay()->toDateString(),
        'active'         => true,
        ...$overrides,
    ]);
}

describe('páginas /privacidade e /termos', function (): void {
    it('mostram a versão vigente do documento oficial', function (string $url, string $type, string $kind): void {
        siteLegalTerm($type, '1.0', ['effective_from' => now()->subMonths(2)->toDateString()]);
        siteLegalTerm($type, '2.0');

        $this->get($url)
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Site/Legal')
                ->where('kind', $kind)
                ->where('document.version', '2.0')
                ->where('document.content', "Primeiro parágrafo da versão 2.0.\n\nSegundo parágrafo.")
                ->where('document.effectiveFrom', fn ($date) => is_string($date) && $date !== '')
                ->has('t.legal.unavailable_text'));
    })->with([
        'privacidade' => ['/privacidade', 'privacy_policy', 'privacy'],
        'termos'      => ['/termos', 'terms_of_service', 'terms'],
    ]);

    it('ignoram versão inativa, versão futura e o outro tipo de documento', function (): void {
        siteLegalTerm('privacy_policy', '3.0', ['active' => false]);
        siteLegalTerm('privacy_policy', '4.0', ['effective_from' => now()->addWeek()->toDateString()]);
        siteLegalTerm('terms_of_service', '1.0');

        $this->get('/privacidade')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('document', null));
    });

    it('sem documento publicado respondem 200 com o aviso e o e-mail de suporte (antes: 404)', function (): void {
        config(['mail.support_address' => 'suporte@exemplo.test']);

        $this->get('/termos')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('document', null)
                ->where('contact.support', 'suporte@exemplo.test'));
    });

    it('o link de Política de Privacidade do consentimento do formulário leva a uma página que existe', function (): void {
        preg_match('/href="([^"]+)"/', (string) trans('site.contact.form.terms'), $match);

        expect($match[1] ?? null)->toBe('/privacidade');
        $this->get($match[1])->assertOk();
    });
});

describe('links do rodapé e contatos na Home', function (): void {
    it('só expõe páginas que existem; as demais vão como null (o rodapé não renderiza)', function (): void {
        config(['docs.api.password' => null]);

        $this->get(route('site.home'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('routes.privacy', route('site.privacy'))
                ->where('routes.terms', route('site.terms'))
                ->where('routes.apiDocs', null)
                ->where('routes.help', null)
                ->where('routes.status', null)
                ->where('routes.about', null)
                ->where('routes.blog', null)
                ->where('routes.partners', null)
                ->where('routes.careers', null)
                ->where('routes.lgpd', null)
                ->where('routes.contactStore', route('contact.store')));
    });

    it('documentação da API aparece só com a senha configurada (sem ela, /docs/api responde 404)', function (): void {
        config(['docs.api.password' => 'segredo-de-teste']);

        $this->get(route('site.home'))
            ->assertInertia(fn (Assert $page) => $page->where('routes.apiDocs', route('docs.api.index')));
    });

    it('e-mails comercial e de suporte vêm da config (nada fixo no front)', function (): void {
        config(['mail.contact_address' => 'vendas@exemplo.test', 'mail.support_address' => 'suporte@exemplo.test']);

        $this->get(route('site.home'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('contact.sales', 'vendas@exemplo.test')
                ->where('contact.support', 'suporte@exemplo.test')
                ->missing('t.contact.support.channel')
                ->where('seo.jsonLd.1.contactPoint.email', 'vendas@exemplo.test'));
    });
});
