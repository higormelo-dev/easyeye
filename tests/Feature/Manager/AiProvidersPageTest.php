<?php

use App\Domains\AI\Models\{AiCatalogSync, AiModelPrice};
use App\Domains\AI\Services\AiProviderSettings;
use App\Enums\AI\AiProvider;
use App\Enums\{ImportStatus, SaasRule};
use App\Models\{Entity, User};
use Illuminate\Support\Facades\DB;

/**
 * Manager → Provedores de IA no padrão das telas do manager: catálogo de
 * modelos paginado/filtrado/ordenado no servidor, números do topo e modelo
 * de cada provedor salvo pelo drawer.
 */
beforeEach(function () {
    $this->saas  = Entity::factory()->create(['is_client' => false, 'active' => true]);
    $this->admin = User::factory()->create();
    createEntityUser($this->saas, $this->admin, SaasRule::Admin->value);

    foreach (AiProvider::cases() as $provider) {
        config()->set("services.{$provider->value}.api_key", null);
    }
    config()->set('services.openai.api_key', 'sk-openai-test-key-123456');
    config()->set('ai.providers.openai.model', 'gpt-5-mini');
    app(AiProviderSettings::class)->setRoleAssignments(['primary' => 'openai', 'reviewer' => null, 'adjudicator' => null]);
});

function asAiPageAdmin(): mixed
{
    return test()->actingAs(test()->admin)->withSession([
        'selected_entity_id' => test()->saas->id, 'selected_entity_is_client' => false, 'selected_entity_user_rule' => 'admin',
    ]);
}

function aiPagePrice(string $provider, string $model, array $extra = []): AiModelPrice
{
    return AiModelPrice::factory()->create(['provider' => $provider, 'model' => $model, ...$extra]);
}

/** @return list<string> */
function aiPageModels(array $query = []): array
{
    $models = [];

    asAiPageAdmin()->get(route('manager.ai-providers.index', $query))->assertOk()
        ->assertInertia(function ($page) use (&$models) {
            $models = collect($page->toArray()['props']['prices']['data'])->pluck('model')->all();
        });

    return $models;
}

describe('catálogo de modelos (servidor)', function () {
    beforeEach(function () {
        aiPagePrice('anthropic', 'claude-sonnet-4-5');
        aiPagePrice('openai', 'gpt-4o', ['input_usd_per_million' => 2.5]);
        aiPagePrice('openai', 'gpt-5-mini', ['input_usd_per_million' => 0.25]);
        aiPagePrice('openai', 'gpt-5.5', ['active' => false, 'source' => AiModelPrice::SOURCE_SYNC]);
        aiPagePrice('gemini', 'gemini-3.6-flash', ['price_locked' => true, 'unlisted_at' => now()]);
    });

    it('padrão: só ativos, o modelo EM USO primeiro (provedor com papel), depois provedor e modelo', function () {
        expect(aiPageModels())->toBe(['gpt-5-mini', 'claude-sonnet-4-5', 'gemini-3.6-flash', 'gpt-4o']);
    });

    it('filtra por status, provedor, origem, situação e busca', function () {
        expect(aiPageModels(['status' => 'inactive']))->toBe(['gpt-5.5'])
            ->and(aiPageModels(['status' => 'all', 'provider' => 'openai']))->toBe(['gpt-5-mini', 'gpt-4o', 'gpt-5.5'])
            ->and(aiPageModels(['status' => 'all', 'source' => 'sync']))->toBe(['gpt-5.5'])
            ->and(aiPageModels(['flag' => 'locked']))->toBe(['gemini-3.6-flash'])
            ->and(aiPageModels(['flag' => 'unlisted']))->toBe(['gemini-3.6-flash'])
            ->and(aiPageModels(['flag' => 'in_use']))->toBe(['gpt-5-mini'])
            ->and(aiPageModels(['search' => 'GPT-4']))->toBe(['gpt-4o']);
    });

    it('ordena pelas colunas da lista branca; valor fora dela volta ao padrão', function () {
        expect(aiPageModels(['sort' => 'input', 'direction' => 'desc'])[0])->toBe('gpt-4o')
            ->and(aiPageModels(['sort' => 'model']))->toBe(['claude-sonnet-4-5', 'gemini-3.6-flash', 'gpt-4o', 'gpt-5-mini'])
            ->and(aiPageModels(['sort' => 'input_usd_per_million; DROP TABLE ai_model_prices']))->toBe(['gpt-5-mini', 'claude-sonnet-4-5', 'gemini-3.6-flash', 'gpt-4o']);

        expect(DB::table('ai_model_prices')->count())->toBe(5);
    });

    it('pagina de 20 em 20 mantendo os filtros nos links', function () {
        foreach (range(1, 25) as $i) {
            aiPagePrice('groq', sprintf('vendor/model-%02d', $i));
        }

        asAiPageAdmin()->get(route('manager.ai-providers.index', ['provider' => 'groq', 'page' => 2]))
            ->assertInertia(fn ($page) => $page
                ->where('prices.current_page', 2)
                ->where('prices.total', 25)
                ->has('prices.data', 5)
                ->where('filters.provider', 'groq')
                ->where('prices.first_page_url', fn ($url) => str_contains($url, 'provider=groq')));
    });

    it('números do topo: provedores configurados, modelos, modo e última sincronização', function () {
        AiCatalogSync::query()->create(['status' => ImportStatus::Done, 'finished_at' => now()->subHour()]);

        asAiPageAdmin()->get(route('manager.ai-providers.index'))
            ->assertInertia(fn ($page) => $page
                ->where('stats.providers_configured', 1)
                ->where('stats.providers_total', count(AiProvider::cases()))
                ->where('stats.models_active', 4)
                ->where('stats.models_inactive', 1)
                ->where('stats.best_mode', __('manager_ai.mode_economy'))
                ->where('stats.last_sync_at', now()->subHour()->isoFormat('L LT'))
                ->where('stats.last_sync_status', ImportStatus::Done->label()));
    });
});

describe('modelo do provedor (drawer)', function () {
    it('salva modelo ativo com preço do próprio provedor e audita', function () {
        aiPagePrice('openai', 'gpt-4o');

        asAiPageAdmin()->patchJson(route('manager.ai-providers.model', 'openai'), ['model' => 'gpt-4o'])
            ->assertOk()
            ->assertJsonPath('message', __('manager_ai.model_saved'));

        expect(app(AiProviderSettings::class)->model('openai'))->toBe('gpt-4o')
            ->and(DB::table('audit_logs')->where('event', 'manager.ai_providers.model')->exists())->toBeTrue();
    });

    it('vazio volta ao padrão do .env', function () {
        app(AiProviderSettings::class)->setModels(['openai' => 'gpt-4o']);

        asAiPageAdmin()->patchJson(route('manager.ai-providers.model', 'openai'), ['model' => null])->assertOk();

        expect(app(AiProviderSettings::class)->model('openai'))->toBe('gpt-5-mini');
    });

    it('recusa modelo sem preço ativo, de outro provedor ou provedor desconhecido', function () {
        aiPagePrice('openai', 'gpt-legacy', ['active' => false]);
        aiPagePrice('anthropic', 'claude-sonnet-4-5');

        asAiPageAdmin()->patchJson(route('manager.ai-providers.model', 'openai'), ['model' => 'gpt-legacy'])->assertStatus(422);
        asAiPageAdmin()->patchJson(route('manager.ai-providers.model', 'openai'), ['model' => 'claude-sonnet-4-5'])->assertStatus(422);
        asAiPageAdmin()->patchJson('/panel/manager/ai-providers/skynet/model', ['model' => 'x'])->assertNotFound();

        expect(app(AiProviderSettings::class)->panelModels())->toBe([]);
    });

    it('[SEGURANÇA] papel SaaS que não é admin não altera', function () {
        aiPagePrice('openai', 'gpt-4o');
        $support = User::factory()->create();
        createEntityUser($this->saas, $support, SaasRule::Support->value);

        $res = test()->actingAs($support)->withSession([
            'selected_entity_id' => $this->saas->id, 'selected_entity_is_client' => false, 'selected_entity_user_rule' => 'support',
        ])->patchJson(route('manager.ai-providers.model', 'openai'), ['model' => 'gpt-4o']);

        expect($res->status())->toBeIn([302, 403])
            ->and(app(AiProviderSettings::class)->panelModels())->toBe([]);
    });
});
