<?php

use App\Broadcasting\ManagerAiCatalogSyncChannel;
use App\Domains\AI\Models\{AiCatalogSync, AiModelPrice};
use App\Domains\AI\Services\AiProviderSettings;
use App\Enums\AI\AiProvider;
use App\Enums\{ClientRule, ImportStatus, SaasRule};
use App\Jobs\SyncAiModelCatalogJob;
use App\Models\{Entity, User};
use Illuminate\Support\Facades\Queue;

/**
 * Manager → Provedores de IA: "Sincronizar agora" (fila + progresso por
 * WebSocket), cancelamento de sincronização parada, canal privado e o que a
 * tela mostra sobre as chaves (só origem e 4 últimos caracteres).
 */
beforeEach(function () {
    $this->saas  = Entity::factory()->create(['is_client' => false, 'active' => true]);
    $this->admin = User::factory()->create();
    createEntityUser($this->saas, $this->admin, SaasRule::Admin->value);
});

function asAiProvidersAdmin(User $user, Entity $entity, string $rule = 'admin')
{
    return test()->actingAs($user)->withSession([
        'selected_entity_id' => $entity->id, 'selected_entity_is_client' => $entity->isClient(), 'selected_entity_user_rule' => $rule,
    ]);
}

describe('sincronizar agora', function () {
    it('enfileira a sincronização e devolve o estado da barra (canal privado)', function () {
        Queue::fake();

        $res = asAiProvidersAdmin($this->admin, $this->saas)->postJson(route('manager.ai-catalog-syncs.store'))
            ->assertOk()
            ->assertJsonPath('message', __('manager_ai.sync_queued'))
            ->assertJsonPath('sync.status', 'pending')
            ->assertJsonPath('sync.source', AiCatalogSync::SOURCE_MANUAL);

        $sync = AiCatalogSync::query()->sole();

        expect($sync->user_id)->toBe($this->admin->id)
            ->and($res->json('sync.channel'))->toBe("manager.ai-catalog-syncs.{$sync->id}");
        Queue::assertPushed(SyncAiModelCatalogJob::class, fn ($job) => $job->import->is($sync));
    });

    it('uma por vez: com outra em andamento responde 422', function () {
        Queue::fake();
        AiCatalogSync::query()->create(['status' => ImportStatus::Processing]);

        asAiProvidersAdmin($this->admin, $this->saas)->postJson(route('manager.ai-catalog-syncs.store'))
            ->assertStatus(422)
            ->assertJsonPath('message', __('manager_ai.sync_in_progress'));

        Queue::assertNothingPushed();
    });

    it('[SEGURANÇA] papel SaaS que não é admin e usuário de clínica não sincronizam', function (string $rule, bool $client) {
        Queue::fake();
        $entity = $client ? Entity::factory()->create(['is_client' => true, 'active' => true]) : $this->saas;
        $user   = User::factory()->create();
        createEntityUser($entity, $user, $rule);

        $res = asAiProvidersAdmin($user, $entity, $rule)->postJson(route('manager.ai-catalog-syncs.store'));

        expect($res->status())->toBeIn([403, 302]);
        expect(AiCatalogSync::query()->exists())->toBeFalse();
    })->with([
        'suporte SaaS'     => [SaasRule::Support->value, false],
        'financeiro SaaS'  => [SaasRule::Financial->value, false],
        'admin de clínica' => [ClientRule::Admin->value, true],
    ]);
});

describe('cancelar sincronização parada', function () {
    it('em andamento normal: recusa', function () {
        $sync = AiCatalogSync::query()->create(['status' => ImportStatus::Processing]);

        asAiProvidersAdmin($this->admin, $this->saas)->postJson(route('manager.ai-catalog-syncs.cancel', $sync->id))
            ->assertStatus(422)
            ->assertJsonPath('message', __('manager_ai.sync_not_stalled'));

        expect($sync->fresh()->status)->toBe(ImportStatus::Processing);
    });

    it('parada na fila além do prazo: cancela, libera nova sincronização e o job não roda mais', function () {
        $sync = AiCatalogSync::query()->create(['status' => ImportStatus::Pending]);
        $this->travel(AiCatalogSync::PENDING_STALL_SECONDS + 5)->seconds();

        asAiProvidersAdmin($this->admin, $this->saas)->postJson(route('manager.ai-catalog-syncs.cancel', $sync->id))
            ->assertOk()
            ->assertJsonPath('sync.status', 'cancelled');

        expect($sync->fresh()->error)->toBe(__('manager_ai.sync_cancelled_reason'));

        Queue::fake();
        asAiProvidersAdmin($this->admin, $this->saas)->postJson(route('manager.ai-catalog-syncs.store'))->assertOk();
        Queue::assertPushed(SyncAiModelCatalogJob::class);
    });
});

describe('autorização do canal manager.ai-catalog-syncs.{id}', function () {
    function joinAiSyncChannel(User $user, Entity $entity, string $id): bool
    {
        session(['selected_entity_id' => $entity->id, 'selected_entity_is_client' => $entity->isClient()]);

        return app(ManagerAiCatalogSyncChannel::class)->join($user, $id);
    }

    beforeEach(function () {
        $this->sync = AiCatalogSync::query()->create(['status' => ImportStatus::Processing]);
    });

    it('admin SaaS entra', function () {
        expect(joinAiSyncChannel($this->admin, $this->saas, $this->sync->id))->toBeTrue();
    });

    it('[SEGURANÇA] suporte SaaS, usuário de clínica, id inválido ou inexistente não entram', function () {
        $support = User::factory()->create();
        createEntityUser($this->saas, $support, SaasRule::Support->value);

        $clinic     = Entity::factory()->create(['is_client' => true, 'active' => true]);
        $clinicUser = User::factory()->create();
        createEntityUser($clinic, $clinicUser, ClientRule::Admin->value);

        expect(joinAiSyncChannel($support, $this->saas, $this->sync->id))->toBeFalse()
            ->and(joinAiSyncChannel($clinicUser, $clinic, $this->sync->id))->toBeFalse()
            ->and(joinAiSyncChannel($this->admin, $this->saas, 'nao-e-uuid'))->toBeFalse()
            ->and(joinAiSyncChannel($this->admin, $this->saas, (string) Str::uuid()))->toBeFalse();
    });
});

describe('tela Provedores de IA', function () {
    beforeEach(function () {
        foreach (AiProvider::cases() as $provider) {
            config()->set("services.{$provider->value}.api_key", null);
        }
        config()->set('services.xai.api_key', 'xai-SEGREDO-abcd1234');
        config()->set('services.groq.api_key', 'curta');
        config()->set('ai.providers.xai.base_url', 'https://user:pass@api.x.ai/v1?token=x');
        config()->set('ai.providers.mistral.base_url', 'http://localhost:4000/v1');
        config()->set('ai.catalog_sync.enabled', true);
    });

    it('[SEGURANÇA] mostra origem e 4 últimos caracteres da chave — nunca o valor', function () {
        $res = asAiProvidersAdmin($this->admin, $this->saas)->get(route('manager.ai-providers.index'))->assertOk();

        $res->assertInertia(fn ($page) => $page
            ->component('Panel/Manager/AiProviders/Index')
            ->where('autoSync', true)
            ->has('providers', count(AiProvider::cases()))
            ->where('providers', fn ($providers) => ($xai = collect($providers)->firstWhere('code', 'xai'))
                && $xai['has_key'] === true
                && $xai['key_hint'] === '••••1234'
                && $xai['key_env'] === 'XAI_API_KEY'
                && $xai['model_env'] === 'AI_XAI_MODEL'
                && $xai['compatible'] === true
                && $xai['configured'] === true
                // Sem usuário/senha/consulta embutidos no endereço exibido.
                && $xai['base_url'] === 'https://api.x.ai/v1'
                && collect($providers)->firstWhere('code', 'groq')['key_hint'] === '••••'
                && collect($providers)->firstWhere('code', 'mistral')['base_url_secure'] === false
                && collect($providers)->firstWhere('code', 'openai')['has_key'] === false
                && collect($providers)->firstWhere('code', 'openai')['key_hint'] === null));

        expect($res->getContent())->not->toContain('SEGREDO')->not->toContain('curta')->not->toContain('user:pass');
    });

    it('configurados vêm antes dos sem chave; modelo em uso não listado aparece no provedor', function () {
        config()->set('ai.providers.xai.model', 'grok-4.5');
        app(AiProviderSettings::class)->setRoleAssignments(['primary' => 'xai', 'reviewer' => null, 'adjudicator' => null]);
        AiModelPrice::factory()->create([
            'provider' => 'xai', 'model' => 'grok-4.5', 'unlisted_at' => now()->subDay(),
        ]);

        asAiProvidersAdmin($this->admin, $this->saas)->get(route('manager.ai-providers.index'))
            ->assertInertia(fn ($page) => $page
                ->where('providers.0.code', 'xai')
                ->where('providers.0.enabled', true)
                ->where('providers.0.price_ok', true)
                ->where('providers.0.model_source', 'env')
                ->where('providers.0.model_unlisted_at', now()->subDay()->isoFormat('L'))
                ->where('providers.1.code', 'groq')
                ->where('providers.0.role', 'primary')
                ->where('prices.data.0.unlisted_at', now()->subDay()->isoFormat('L'))
                ->where('prices.data.0.in_use', true));
    });

    it('traz a sincronização em andamento, o histórico e o que mudou na última', function () {
        AiCatalogSync::query()->create([
            'status'  => ImportStatus::Done, 'finished_at' => now()->subHour(), 'updated_count' => 1,
            'details' => ['updated' => [['provider' => 'openai', 'model' => 'gpt-4o', 'old' => ['input' => 5, 'output' => 15], 'new' => ['input' => 2.5, 'output' => 10]]]],
        ]);
        $running = AiCatalogSync::query()->create(['status' => ImportStatus::Pending, 'source' => AiCatalogSync::SOURCE_SCHEDULED]);

        asAiProvidersAdmin($this->admin, $this->saas)->get(route('manager.ai-providers.index'))
            ->assertInertia(fn ($page) => $page
                ->where('runningSync.id', $running->id)
                ->where('runningSync.source_label', __('manager_ai.sync_source_scheduled'))
                ->where('runningSync.stall_after_seconds', AiCatalogSync::PENDING_STALL_SECONDS)
                ->has('syncs', 2)
                ->where('syncDetails.lists.updated.0.provider_label', 'OpenAI')
                ->where('syncDetails.lists.updated.0.model', 'gpt-4o'));
    });
});
