<?php

declare(strict_types=1);

use App\Models\SubscriptionSetting;
use Inertia\Testing\AssertableInertia as Assert;

it('não serializa rascunhos de prova social nas páginas públicas', function (string $locale) {
    SubscriptionSetting::setValue('trial_days', 7);
    // Dados sintéticos simulam o preenchimento futuro antes de liberar sua publicação.
    $draft = 'SOCIAL_PROOF_DRAFT_DO_NOT_PUBLISH';
    trans('site', [], $locale);
    app('translator')->addLines([
        'site.hero.trust'                 => $draft,
        'site.hero.trust_initials'        => [$draft],
        'site.metrics'                    => [['value' => $draft, 'label' => $draft]],
        'site.metrics_context'            => $draft,
        'site.metrics_context_label'      => $draft,
        'site.testimonials.items'         => [['name' => $draft, 'text' => $draft]],
        'site.testimonials.context'       => $draft,
        'site.contact.aside.quote_text'   => $draft,
        'site.contact.aside.quote_author' => $draft,
        'site.contact.trust_nps'          => $draft,
    ], $locale);

    foreach (['site.home', 'register', 'site.privacy', 'site.terms'] as $route) {
        $this->withSession(['locale' => $locale])->get(route($route))
            ->assertOk()
            ->assertDontSee($draft, false)
            ->assertInertia(fn (Assert $page) => $page
                ->where('t.nav.testimonials', null));

        $this->withSession(['locale' => $locale])->getJson(route($route), ['X-Inertia' => 'true'])
            ->assertOk()
            ->assertDontSee($draft, false)
            ->assertJsonPath('props.t.nav.testimonials', null);
    }
})->with(['pt_BR', 'en']);
