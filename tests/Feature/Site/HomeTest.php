<?php

declare(strict_types=1);

use App\Enums\FeatureKey;
use App\Models\{Plan, PlanFeature};
use Inertia\Testing\AssertableInertia as Assert;

/**
 * Landing page pública (/) — reorganização "produto antes do preço".
 *
 * Cobre especificamente os dois bugs reais corrigidos nesta mudança:
 *  - nenhum plano tinha is_featured=true (o card "Mais popular" nunca acendia);
 *  - plan.slug não ia para o front (o callout exclusivo do Premium dependia disso).
 * E trava a decisão de produto: optotipos ainda não existe no produto —
 * sempre com o selo "novidade/em breve", nunca como já incluído. Estoque
 * foi REMOVIDO desta lista (GAP fechado — pedido do usuário): o módulo
 * passou a vir incluso em TODOS os planos, então não é mais exclusividade
 * do Premium.
 */
it('renderiza a home com todas as novas seções de conteúdo', function () {
    $this->get(route('site.home'))
        ->assertOk()
        ->assertInertia(
            fn (Assert $page) => $page
                // GAP fechado (revisão pós-Fase 4 do módulo de estoque):
                // config/inertia.php apontava pages.paths pra
                // resource_path('js/pages') minúsculo — corrigido pra
                // 'js/Pages' (case real do diretório em filesystem
                // case-sensitive). ->component() sem shouldExist=false
                // agora resolve de verdade, sem workaround.
                ->component('Site/Home')
                ->has('t.problems.items', 6)
                ->has('t.benefits.items', 8)
                ->has('t.demo.tabs', 4)
                ->has('t.differentiators.items')
                // 1 item só (optotipos) — estoque saiu daqui, ver teste
                // dedicado abaixo.
                ->has('t.differentiators.premium_callout.items', 1)
                ->has('demoImages.prontuario')
                ->has('demoImages.agenda')
                ->has('demoImages.imagens')
                ->has('demoImages.laudos'),
        );
});

it('o Plano Pro é o plano em destaque (is_featured) e expõe slug', function () {
    $plan = Plan::factory()->create([
        'slug'        => 'pro',
        'name'        => 'Pro',
        'active'      => true,
        'is_featured' => true,
        'sort_order'  => 2,
    ]);
    PlanFeature::factory()->enabled(FeatureKey::HasAiReportDrafting)->for($plan)->create();

    $this->get(route('site.home'))
        ->assertInertia(
            fn (Assert $page) => $page
                ->where('plans.0.slug', 'pro')
                ->where('plans.0.is_featured', true),
        );
});

it('optotipos nunca é apresentado como já incluído — sempre com selo de novidade', function () {
    $this->get(route('site.home'))
        ->assertInertia(
            fn (Assert $page) => $page
                ->where('t.differentiators.premium_callout.items.0.badge', fn ($badge) => $badge !== '' && strtolower((string) $badge) !== 'incluído')
                ->where('t.pricing.premium_exclusive_new', fn ($label) => $label !== ''),
        );
});

it('[GAP] módulo de estoque NÃO aparece mais como exclusividade do Premium — está incluso em todos os planos', function () {
    $this->get(route('site.home'))
        ->assertInertia(
            fn (Assert $page) => $page
                ->where('t.differentiators.premium_callout.items', fn ($items) => collect($items)
                    ->pluck('title')
                    ->doesntContain(fn ($title) => str_contains(mb_strtolower((string) $title), 'estoque'))),
        );
});

it('funciona normalmente sem nenhum plano cadastrado (estado vazio)', function () {
    Plan::query()->delete();

    $this->get(route('site.home'))
        ->assertOk()
        ->assertInertia(
            fn (Assert $page) => $page
            // shouldExist=false: config/inertia.php aponta pages.paths para
            // resource_path('js/pages') minúsculo, mas o diretório real é
            // resources/js/Pages — em filesystem case-sensitive (Linux) o
            // page-finder nunca encontra NENHUM componente. Bug pré-existente
            // e já documentado, fora do escopo desta mudança; contornado aqui
            // como as demais suítes do projeto fazem.
                ->component('Site/Home', false)
                ->where('plans', []),
        );
});
