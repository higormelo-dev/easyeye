<?php

declare(strict_types=1);

use App\Support\Site\SiteContent;
use Tests\TestCase;

uses(TestCase::class);

it('mantém a publicação de prova social desativada por padrão', function () {
    expect(config('site.social_proof_enabled'))->toBeFalse();
});

it('permite publicar conteúdo revisado sem alterar as outras traduções', function (string $locale) {
    app()->setLocale($locale);
    trans('site');
    app('translator')->addLines([
        'site.hero.trust'                 => 'Confiança de uma clínica de teste',
        'site.hero.trust_initials'        => ['CT'],
        'site.metrics'                    => [['value' => '1', 'label' => 'Indicador de teste']],
        'site.metrics_context'            => 'Fonte de teste',
        'site.testimonials.items'         => [['name' => 'Pessoa de teste', 'text' => 'Depoimento de teste']],
        'site.testimonials.context'       => 'Contexto de teste',
        'site.contact.aside.quote_text'   => 'Citação de teste',
        'site.contact.aside.quote_author' => 'Pessoa de teste',
        'site.contact.trust_nps'          => 'Satisfação de teste',
    ], $locale);
    config(['site.social_proof_enabled' => true]);

    expect(SiteContent::translations())->toBe(trans('site'));

    config(['site.social_proof_enabled' => false]);
    $hidden = SiteContent::translations();
    expect($hidden['metrics'])->toBe([])
        ->and($hidden['testimonials']['items'])->toBe([])
        ->and($hidden['nav']['testimonials'])->toBeNull()
        ->and($hidden['pricing'])->toBe(trans('site.pricing'))
        // A filtragem não apaga os dados de origem destinados à publicação futura.
        ->and(trans('site.metrics.0.value'))->toBe('1');
})->with(['pt_BR', 'en']);

it('omite o link para depoimentos quando não há conteúdo mesmo com publicação ativada', function () {
    config(['site.social_proof_enabled' => true]);
    trans('site');
    app('translator')->addLines(['site.testimonials.items' => []], app()->getLocale());

    expect(SiteContent::translations()['nav']['testimonials'])->toBeNull();
});
