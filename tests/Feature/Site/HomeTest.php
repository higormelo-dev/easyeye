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
 *  - plan.slug não ia para o front (o bloco "Em breve" do Premium depende disso).
 * E trava a decisão de produto: optotipos ainda não existe no produto —
 * sempre "Em breve" e só no card do Premium, nunca como já incluído. Estoque
 * saiu das exclusividades (GAP fechado — pedido do usuário): o módulo passou
 * a vir incluso em TODOS os planos.
 *
 * Depois da crítica de 2026-09-27: 4 dores (antes 6), Benefícios +
 * Funcionalidades viraram 3 blocos por público, e cada recurso de plano leva a
 * chave (`key`) e `is_none` para a Home comparar planos e omitir ausências.
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
                ->has('t.problems.items', 4)
                ->has('t.audiences.groups', 3)
                ->missing('t.benefits')
                ->missing('t.features')
                ->missing('t.compliance')
                ->has('t.demo.tabs', 4)
                ->has('t.differentiators.items', 4)
                ->has('t.differentiators.proof', 6)
                ->missing('t.differentiators.premium_callout')
                // 1 item só (optotipos) — estoque saiu daqui, ver teste
                // dedicado abaixo.
                ->has('t.pricing.upcoming', 1)
                // false quando o arquivo não existe (a Home esconde o print, sem imagem quebrada)
                ->has('heroImage')
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

it('optotipos nunca é apresentado como já incluído — só "Em breve" no card do Premium', function () {
    $this->get(route('site.home'))
        ->assertInertia(
            fn (Assert $page) => $page
                ->has('t.pricing.upcoming', 1)
                ->where('t.pricing.upcoming.0.badge', trans('site.pricing.upcoming.0.badge'))
                ->where('t.pricing.upcoming.0.title', trans('site.pricing.upcoming.0.title')),
        );

    // Selo nos dois idiomas; e fora do que todo plano tem e dos blocos de funcionalidades.
    expect(trans('site.pricing.upcoming.0.badge', [], 'pt_BR'))->toBe('Em breve')
        ->and(trans('site.pricing.upcoming.0.badge', [], 'en'))->toBe('Coming soon')
        ->and(trans('site.pricing.upcoming.0.title', [], 'pt_BR'))->toContain('optotipos')
        ->and(mb_strtolower(trans('site.pricing.included_all', [], 'pt_BR')))->not->toContain('optotip')
        ->and(mb_strtolower(json_encode(trans('site.audiences', [], 'pt_BR'), JSON_UNESCAPED_UNICODE)))->not->toContain('optotip');
});

it('[GAP] módulo de estoque NÃO aparece mais como exclusividade do Premium — está incluso em todos os planos', function () {
    $this->get(route('site.home'))
        ->assertInertia(
            fn (Assert $page) => $page
                ->where('t.pricing.upcoming', fn ($items) => collect($items)
                    ->pluck('title')
                    ->doesntContain(fn ($title) => str_contains(mb_strtolower((string) $title), 'estoque')
                        || str_contains(mb_strtolower((string) $title), 'inventory'))),
        );
});

it('cada recurso do plano leva a chave e marca "nenhum" (créditos de IA = 0) para a Home omitir ausências', function () {
    $plan = Plan::factory()->create(['slug' => 'basico', 'name' => 'Básico', 'active' => true, 'sort_order' => 1]);
    PlanFeature::factory()->limit(FeatureKey::AiMonthlyCredits, 0)->for($plan)->create();
    PlanFeature::factory()->limit(FeatureKey::MaxDoctors, 1)->for($plan)->create();
    PlanFeature::factory()->disabled(FeatureKey::HasApiIntegrator)->for($plan)->create();

    $this->get(route('site.home'))
        ->assertInertia(
            fn (Assert $page) => $page->where('plans.0.features', function ($features) {
                $byKey = collect($features)->keyBy('key');

                return $byKey->has(['ai_monthly_credits', 'max_doctors', 'has_api_integrator'])
                    && $byKey['ai_monthly_credits']['is_none'] === true
                    && $byKey['max_doctors']['is_none'] === false
                    && $byKey['max_doctors']['enabled'] === true
                    && $byKey['has_api_integrator']['enabled'] === false;
            }),
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
