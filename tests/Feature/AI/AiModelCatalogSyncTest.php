<?php

use App\Domains\AI\Models\{AiCatalogSync, AiModelPrice, AiProviderTopup};
use App\Domains\AI\Services\AiUsdBrlRate;
use App\Domains\AI\Services\Catalog\AiModelCatalogSyncService;
use App\Enums\AI\AiProvider;
use App\Enums\ImportStatus;
use App\Events\ImportProgressUpdated;
use App\Jobs\SyncAiModelCatalogJob;
use App\Models\AuditLog;
use Illuminate\Console\Scheduling\{Event as ScheduledEvent, Schedule};
use Illuminate\Http\Client\Request;
use Illuminate\Queue\MaxAttemptsExceededException;
use Illuminate\Support\Facades\{DB, Event, Http, Queue};
use Illuminate\Support\Number;

/**
 * Sincronização do catálogo de modelos/preços de IA (Manager → Provedores de
 * IA): modelos pela API de cada provedor com chave no .env + preços do
 * catálogo público LiteLLM. Preço travado e variação suspeita nunca mudam
 * sozinhos; modelo novo entra inativo; chave nunca sai do .env.
 */
beforeEach(function () {
    config()->set('ai.catalog_sync.prices_url', 'https://catalog.test/prices.json');

    foreach (AiProvider::cases() as $provider) {
        config()->set("services.{$provider->value}.api_key", null);
    }

    config()->set('services.openai.api_key', 'sk-openai-test-key-123456');
    config()->set('ai.providers.openai.base_url', 'https://api.openai.com/v1');
    config()->set('ai.providers.openai.model', 'gpt-5-mini');
    config()->set('services.gemini.api_key', 'AIzaGeminiTestKey0000000000000');
    config()->set('ai.providers.gemini.base_url', 'https://generativelanguage.googleapis.com');
    config()->set('ai.providers.gemini.model', 'gemini-3.6-flash');
    config()->set('ai.providers.anthropic.base_url', 'https://api.anthropic.com');

    $per = fn (float $perMillion) => $perMillion / 1_000_000;

    // Formato do model_prices_and_context_window.json (preço por token).
    $this->catalog = [
        'sample_spec'             => ['litellm_provider' => 'one of https://docs.litellm.ai/docs/providers', 'mode' => 'chat'],
        'gpt-5-mini'              => ['litellm_provider' => 'openai', 'mode' => 'chat', 'input_cost_per_token' => $per(0.25), 'output_cost_per_token' => $per(2.0)],
        'gpt-4o'                  => ['litellm_provider' => 'openai', 'mode' => 'chat', 'input_cost_per_token' => $per(2.5), 'output_cost_per_token' => $per(10.0)],
        'gpt-4o-2024-08-06'       => ['litellm_provider' => 'openai', 'mode' => 'chat', 'input_cost_per_token' => $per(2.5), 'output_cost_per_token' => $per(10.0)],
        'gpt-5.5'                 => ['litellm_provider' => 'openai', 'mode' => 'chat', 'input_cost_per_token' => $per(5.0), 'output_cost_per_token' => $per(30.0)],
        'o1-pro'                  => ['litellm_provider' => 'openai', 'mode' => 'responses', 'input_cost_per_token' => $per(150.0), 'output_cost_per_token' => $per(600.0)],
        'text-embedding-3-small'  => ['litellm_provider' => 'openai', 'mode' => 'embedding', 'input_cost_per_token' => $per(0.02), 'output_cost_per_token' => 0],
        'claude-sonnet-4-5'       => ['litellm_provider' => 'anthropic', 'mode' => 'chat', 'input_cost_per_token' => $per(3.0), 'output_cost_per_token' => $per(15.0)],
        'claude-opus-5'           => ['litellm_provider' => 'anthropic', 'mode' => 'chat', 'input_cost_per_token' => $per(5.0), 'output_cost_per_token' => $per(25.0)],
        'gemini/gemini-3.6-flash' => ['litellm_provider' => 'gemini', 'mode' => 'chat', 'input_cost_per_token' => $per(0.75), 'output_cost_per_token' => $per(3.75), 'output_cost_per_reasoning_token' => $per(3.75)],
        'gemini/gemini-3.7-pro'   => ['litellm_provider' => 'gemini', 'mode' => 'chat', 'input_cost_per_token' => $per(2.0), 'output_cost_per_token' => $per(12.0)],
        // Mesmo modelo em outro provedor do catálogo (Vertex): ignorado.
        'gemini-3.7-pro' => ['litellm_provider' => 'vertex_ai-language-models', 'mode' => 'chat', 'input_cost_per_token' => $per(99.0), 'output_cost_per_token' => $per(99.0)],
    ];

    $this->openaiStatus = 200;
    $this->openaiModels = ['gpt-5-mini', 'gpt-4o', 'gpt-4o-2024-08-06', 'gpt-5.5', 'o1-pro', 'text-embedding-3-small', 'whisper-1', 'brand-new-model'];
    $this->geminiModels = [
        ['name' => 'models/gemini-3.6-flash', 'supportedGenerationMethods' => ['generateContent', 'countTokens']],
        ['name' => 'models/gemini-3.7-pro', 'supportedGenerationMethods' => ['generateContent']],
        ['name' => 'models/gemini-embedding-001', 'supportedGenerationMethods' => ['embedContent']],
        ['name' => 'models/gemini-9-experimental', 'supportedGenerationMethods' => ['generateContent']],
    ];
    $this->catalogResponse = null;

    // Registrado uma vez: cada teste muda as propriedades acima.
    Http::fake([
        'https://catalog.test/*' => fn () => test()->catalogResponse
            ?? Http::response(json_encode(test()->catalog), 200, ['ETag' => '"v1"']),
        'https://api.openai.com/v1/models' => fn () => test()->openaiStatus === 200
            ? Http::response(['object' => 'list', 'data' => array_map(fn ($id) => ['id' => $id, 'object' => 'model'], test()->openaiModels)])
            : Http::response(['error' => ['message' => 'Incorrect API key provided: sk-openai-test-key-123456']], test()->openaiStatus),
        'https://generativelanguage.googleapis.com/v1beta/models*' => fn () => Http::response(['models' => test()->geminiModels]),
        'https://api.anthropic.com/v1/models*'                     => fn (Request $request) => str_contains($request->url(), 'after_id=page2')
            ? Http::response(['data' => [['type' => 'model', 'id' => 'claude-opus-5']], 'has_more' => false, 'last_id' => 'claude-opus-5'])
            : Http::response(['data' => [['type' => 'model', 'id' => 'claude-sonnet-4-5-20250929']], 'has_more' => true, 'last_id' => 'page2']),
    ]);
});

function catalogPrice(string $provider, string $model, float $input, float $output, array $extra = []): AiModelPrice
{
    return AiModelPrice::factory()->create([
        'provider'               => $provider,
        'model'                  => $model,
        'input_usd_per_million'  => $input,
        'output_usd_per_million' => $output,
        ...$extra,
    ]);
}

function runCatalogSync(): AiCatalogSync
{
    $sync = AiCatalogSync::query()->create(['source' => AiCatalogSync::SOURCE_MANUAL, 'status' => ImportStatus::Pending]);

    app(AiModelCatalogSyncService::class)->run($sync);

    return $sync->fresh();
}

function syncedRow(string $provider, string $model): ?AiModelPrice
{
    return AiModelPrice::query()->where('provider', $provider)->where('model', $model)->first();
}

it('atualiza o preço que mudou, carimba os conferidos e audita só a mudança de preço', function () {
    // Raciocínio = saída: efetivo igual ao catálogo (sem preço próprio) → sem mudança.
    $same    = catalogPrice('openai', 'gpt-5-mini', 0.25, 2.00, ['reasoning_usd_per_million' => 2.00]);
    $changed = catalogPrice('openai', 'gpt-4o', 5.00, 15.00);

    $sync = runCatalogSync();

    expect($sync->status)->toBe(ImportStatus::Done)
        ->and($sync->prices_version)->toBe('v1')
        ->and($sync->updated_count)->toBe(1)
        ->and($sync->unchanged_count)->toBe(1)
        ->and($sync->details['updated'][0])->toMatchArray([
            'provider' => 'openai',
            'model'    => 'gpt-4o',
            'old'      => ['input' => 5.0, 'output' => 15.0],
            'new'      => ['input' => 2.5, 'output' => 10.0],
        ]);

    $changed->refresh();
    $same->refresh();

    expect((float) $changed->input_usd_per_million)->toBe(2.5)
        ->and((float) $changed->output_usd_per_million)->toBe(10.0)
        ->and($changed->synced_at)->not->toBeNull()
        ->and((float) $same->reasoning_usd_per_million)->toBe(2.0)
        ->and($same->synced_at)->not->toBeNull();

    $audited = fn (AiModelPrice $p) => AuditLog::query()->where('auditable_id', $p->id)->where('event', 'updated')->exists();

    expect($audited($changed))->toBeTrue()->and($audited($same))->toBeFalse();
});

it('modelo novo entra INATIVO (o em uso entra ativo); snapshot datado, não-texto e sem preço ficam de fora', function () {
    catalogPrice('openai', 'gpt-4o', 2.5, 10.0);

    $sync = runCatalogSync();

    $new = syncedRow('openai', 'gpt-5.5');
    expect($new->active)->toBeFalse()
        ->and($new->source)->toBe(AiModelPrice::SOURCE_SYNC)
        ->and($new->price_locked)->toBeFalse()
        ->and((float) $new->input_usd_per_million)->toBe(5.0)
        ->and((float) $new->output_usd_per_million)->toBe(30.0)
        // API de respostas (só a integração própria da OpenAI usa) também entra.
        ->and(syncedRow('openai', 'o1-pro'))->not->toBeNull();

    // Modelo em uso do Gemini (padrão do .env) ainda sem preço cadastrado: entra ativo.
    $inUse = syncedRow('gemini', 'gemini-3.6-flash');
    expect($inUse->active)->toBeTrue()
        ->and((float) $inUse->reasoning_usd_per_million)->toBe(3.75)
        ->and(syncedRow('gemini', 'gemini-3.7-pro')->active)->toBeFalse()
        // Preço do Vertex (outro provedor do catálogo) não vale para o Gemini.
        ->and((float) syncedRow('gemini', 'gemini-3.7-pro')->input_usd_per_million)->toBe(2.0);

    foreach (['gpt-4o-2024-08-06', 'text-embedding-3-small', 'whisper-1', 'brand-new-model'] as $model) {
        expect(syncedRow('openai', $model))->toBeNull();
    }

    expect(syncedRow('gemini', 'gemini-embedding-001'))->toBeNull()
        // gpt-5-mini (em uso na OpenAI) também entra — ativo.
        ->and(syncedRow('openai', 'gpt-5-mini')->active)->toBeTrue()
        ->and($sync->created_count)->toBe(5)
        ->and($sync->missing_price_count)->toBe(2)
        ->and(collect($sync->details['missing_price'])->pluck('model')->sort()->values()->all())->toBe(['brand-new-model', 'gemini-9-experimental'])
        // Descobertos em massa: a trilha é o histórico da sincronização, não um audit por linha.
        ->and(AuditLog::query()->where('auditable_id', $new->id)->exists())->toBeFalse();

    // Rodar de novo não duplica.
    runCatalogSync();
    expect(AiModelPrice::query()->where('provider', 'openai')->where('model', 'gpt-5.5')->count())->toBe(1);
});

it('preço TRAVADO (editado à mão) não muda — vira item para revisar quando difere do catálogo', function () {
    $locked = catalogPrice('openai', 'gpt-4o', 4.00, 12.00, ['price_locked' => true]);

    $sync = runCatalogSync();

    expect((float) $locked->fresh()->input_usd_per_million)->toBe(4.0)
        ->and($sync->locked_count)->toBe(1)
        ->and($sync->updated_count)->toBe(0)
        ->and($sync->details['locked'][0])->toMatchArray(['model' => 'gpt-4o', 'new' => ['input' => 2.5, 'output' => 10.0]]);
});

it('[COBRANÇA] variação suspeita (mais de 10x ou preço zerado) não é aplicada e gera aviso', function () {
    $tooCheap                                            = catalogPrice('openai', 'gpt-4o', 30.00, 120.00); // catálogo 2.5/10 = 12x menor
    $zeroed                                              = catalogPrice('openai', 'gpt-5-mini', 0.25, 2.00);
    $this->catalog['gpt-5-mini']['input_cost_per_token'] = 0;

    $sync = runCatalogSync();

    expect((float) $tooCheap->fresh()->input_usd_per_million)->toBe(30.0)
        ->and((float) $zeroed->fresh()->input_usd_per_million)->toBe(0.25)
        ->and($sync->suspicious_count)->toBe(2)
        ->and($sync->notice)->toContain(__('manager_ai.sync_notice_suspicious', ['count' => 2]));
});

it('provedor SEM chave: só confere preços dos já cadastrados — nenhuma chamada à API dele', function () {
    $claude = catalogPrice('anthropic', 'claude-sonnet-4-5', 2.00, 8.00);

    $sync = runCatalogSync();

    $anthropic = collect($sync->providerResults())->firstWhere('code', 'anthropic');

    expect($anthropic['status'])->toBe(AiCatalogSync::PROVIDER_SKIPPED)
        ->and($anthropic['message'])->toBe(__('manager_ai.sync_provider_no_key'))
        ->and((float) $claude->fresh()->input_usd_per_million)->toBe(3.0)
        ->and(syncedRow('anthropic', 'claude-opus-5'))->toBeNull();

    Http::assertNotSent(fn (Request $request) => str_contains($request->url(), 'api.anthropic.com'));
});

it('[SEGURANÇA] chave recusada (401) não derruba a sincronização e nunca vai para o banco', function () {
    $this->openaiStatus = 401;
    $gpt4o              = catalogPrice('openai', 'gpt-4o', 5.00, 15.00);

    $sync = runCatalogSync();

    $openai = collect($sync->providerResults())->firstWhere('code', 'openai');

    expect($sync->status)->toBe(ImportStatus::Done)
        ->and($openai['status'])->toBe(AiCatalogSync::PROVIDER_FAILED)
        ->and($openai['message'])->toBe(__('manager_ai.sync_provider_unauthorized', ['status' => 401]))
        ->and($sync->notice)->toContain('OpenAI')
        // Preços dos já cadastrados seguem conferidos pelo catálogo.
        ->and((float) $gpt4o->fresh()->input_usd_per_million)->toBe(2.5)
        // Sem listagem, nada novo entra e nada é marcado como não listado.
        ->and(syncedRow('openai', 'gpt-5.5'))->toBeNull()
        ->and($gpt4o->fresh()->unlisted_at)->toBeNull();

    $stored = json_encode(DB::table('ai_catalog_syncs')->get()) . json_encode(DB::table('ai_model_prices')->get());

    expect($stored)->not->toContain('sk-openai-test-key')->not->toContain('AIzaGeminiTestKey');
});

it('modelo ATIVO que o provedor deixou de oferecer é marcado; voltou a oferecer, desmarca', function () {
    config()->set('ai.providers.openai.model', 'gpt-4-turbo');
    $gone     = catalogPrice('openai', 'gpt-4-turbo', 10.0, 30.0);
    $inactive = catalogPrice('openai', 'gpt-3.5-turbo', 0.5, 1.5, ['active' => false]);

    $sync = runCatalogSync();

    expect($gone->fresh()->unlisted_at)->not->toBeNull()
        ->and($gone->fresh()->active)->toBeTrue() // nunca desativa sozinho (a cobrança não quebra)
        ->and($inactive->fresh()->unlisted_at)->toBeNull()
        ->and($sync->unlisted_count)->toBe(1)
        ->and($sync->details['unlisted'][0])->toMatchArray(['model' => 'gpt-4-turbo', 'in_use' => true]);

    $this->openaiModels[] = 'gpt-4-turbo';
    runCatalogSync();

    expect($gone->fresh()->unlisted_at)->toBeNull();
});

it('Anthropic: pagina a listagem com x-api-key e o snapshot datado conta para o modelo-base', function () {
    config()->set('services.anthropic.api_key', 'sk-ant-test-key-0000000');
    $base = catalogPrice('anthropic', 'claude-sonnet-4-5', 3.0, 15.0);

    runCatalogSync();

    expect($base->fresh()->unlisted_at)->toBeNull()
        ->and(syncedRow('anthropic', 'claude-sonnet-4-5-20250929'))->toBeNull()
        ->and(syncedRow('anthropic', 'claude-opus-5'))->not->toBeNull();

    Http::assertSent(fn (Request $request) => str_contains($request->url(), 'api.anthropic.com/v1/models?limit=1000&after_id=page2')
        && $request->hasHeader('x-api-key', 'sk-ant-test-key-0000000')
        && $request->hasHeader('anthropic-version'));
});

it('[SEGURANÇA] Gemini: chave no header, nunca na URL', function () {
    runCatalogSync();

    Http::assertSent(fn (Request $request) => str_starts_with($request->url(), 'https://generativelanguage.googleapis.com/v1beta/models')
        && $request->hasHeader('x-goog-api-key', 'AIzaGeminiTestKey0000000000000')
        && ! str_contains($request->url(), 'AIza'));
});

it('catálogo de preços fora do ar ou em formato inesperado: falha sem mexer em nada', function (mixed $response, string $error) {
    $this->catalogResponse = $response;
    $row                   = catalogPrice('openai', 'gpt-4o', 5.0, 15.0);

    $sync = runCatalogSync();

    expect($sync->status)->toBe(ImportStatus::Failed)
        ->and($sync->error)->toBe(__($error))
        ->and((float) $row->fresh()->input_usd_per_million)->toBe(5.0)
        ->and(AiModelPrice::query()->count())->toBe(1);

    Http::assertNotSent(fn (Request $request) => str_contains($request->url(), 'api.openai.com'));
})->with([
    'HTTP 500'         => [fn () => Http::response('erro', 500), 'manager_ai.sync_prices_unavailable'],
    'JSON inválido'    => [fn () => Http::response('{nao-e-json', 200), 'manager_ai.sync_prices_invalid'],
    'sem preço nenhum' => [fn () => Http::response(['foo' => ['mode' => 'chat']], 200), 'manager_ai.sync_prices_invalid'],
]);

it('[SEGURANÇA] endereço do catálogo sem HTTPS é recusado sem requisição', function () {
    config()->set('ai.catalog_sync.prices_url', 'http://catalog.test/prices.json');

    $sync = runCatalogSync();

    expect($sync->status)->toBe(ImportStatus::Failed)
        ->and($sync->error)->toBe(__('manager_ai.sync_prices_unavailable'));

    Http::assertNothingSent();
});

it('transmite o progresso no canal da sincronização até o estado final', function () {
    Event::fake([ImportProgressUpdated::class]);

    $sync = runCatalogSync();

    // Lido do banco (jsonb reordena as chaves): mesma ordem da lista pronta.
    expect(array_column($sync->providerResults(), 'code'))->toBe(array_map(fn (AiProvider $p) => $p->value, AiProvider::cases()));

    Event::assertDispatched(ImportProgressUpdated::class, fn (ImportProgressUpdated $e) => $e->channel === "manager.ai-catalog-syncs.{$sync->id}"
        && $e->payload['is_done'] === true
        && $e->payload['status'] === ImportStatus::Done->value
        && count($e->payload['providers']) === count(AiProvider::cases())
        // Na ordem da lista pronta (o jsonb do Postgres reordena as chaves).
        && array_column($e->payload['providers'], 'code') === array_map(fn (AiProvider $p) => $p->value, AiProvider::cases())
        // Mensagem enxuta: os detalhes não vão pelo WebSocket.
        && ! array_key_exists('details', $e->payload));
});

describe('fila, comando e agendamento', function () {
    it('job ignora sincronização que não está mais na fila (cancelada/terminada)', function () {
        Http::fake();
        $sync = AiCatalogSync::query()->create(['status' => ImportStatus::Cancelled]);

        (new SyncAiModelCatalogJob($sync))->handle(app(AiModelCatalogSyncService::class));

        expect($sync->fresh()->status)->toBe(ImportStatus::Cancelled);
        Http::assertNothingSent();
    });

    it('[FILA] worker morto encerra a sincronização como falha', function () {
        $sync = AiCatalogSync::query()->create(['status' => ImportStatus::Processing, 'phase' => AiCatalogSync::PHASE_MODELS]);

        (new SyncAiModelCatalogJob($sync))->failed(new MaxAttemptsExceededException('x'));

        expect($sync->fresh()->status)->toBe(ImportStatus::Failed)
            ->and($sync->fresh()->error)->toBe(__('manager_ai.sync_failed_generic'));
    });

    it('comando enfileira a verificação diária e não duplica com uma em andamento', function () {
        Queue::fake();

        $this->artisan('ai:sync-model-catalog')->assertSuccessful();
        $this->artisan('ai:sync-model-catalog')->assertSuccessful();

        $sync = AiCatalogSync::query()->sole();
        expect($sync->source)->toBe(AiCatalogSync::SOURCE_SCHEDULED)->and($sync->user_id)->toBeNull();
        Queue::assertPushed(SyncAiModelCatalogJob::class, 1);
    });

    it('agendamento: todo dia às 03:40, sem sobreposição, ligado pela variável de ambiente', function () {
        $event = collect(app(Schedule::class)->events())
            ->first(fn (ScheduledEvent $e) => str_contains((string) $e->command, 'ai:sync-model-catalog'));

        expect($event)->not->toBeNull()
            ->and($event->expression)->toBe('40 3 * * *')
            ->and($event->withoutOverlapping)->toBeTrue();

        config(['ai.catalog_sync.enabled' => false]);
        expect($event->filtersPass(app()))->toBeFalse();

        config(['ai.catalog_sync.enabled' => true]);
        expect($event->filtersPass(app()))->toBeTrue();
    });
});

describe('Azure OpenAI e Maritaca', function () {
    beforeEach(function () {
        config()->set('services.maritaca.api_key', 'maritaca-test-key-000000');
        config()->set('ai.providers.maritaca.base_url', 'https://chat.maritaca.ai/api');
        config()->set('services.azure_openai.api_key', 'azure-test-key-0000000000');
        config()->set('ai.providers.azure_openai.base_url', 'https://easyeye-se.openai.azure.com/openai/v1');

        Http::fake(['https://chat.maritaca.ai/api/models' => Http::response(['data' => [
            ['id' => 'sabia-4-br-sp'], ['id' => 'sabia-4'], ['id' => 'sabia-futuro'],
        ]])]);
    });

    it('Maritaca: lista com "Authorization: Key" e converte o preço oficial em R$ pela cotação da última recarga', function () {
        AiProviderTopup::query()->create([
            'provider' => 'openai', 'amount_usd' => 100, 'amount_brl' => 500, 'exchange_rate' => 5.0, 'topped_up_at' => now()->subDay(),
        ]);

        $sync = runCatalogSync();

        expect((float) syncedRow('maritaca', 'sabia-4-br-sp')->input_usd_per_million)->toBe(1.3)
            ->and((float) syncedRow('maritaca', 'sabia-4-br-sp')->output_usd_per_million)->toBe(5.2)
            ->and((float) syncedRow('maritaca', 'sabia-4')->input_usd_per_million)->toBe(1.0)
            // Sem preço oficial na tabela: fica de fora (cadastre à mão).
            ->and(syncedRow('maritaca', 'sabia-futuro'))->toBeNull()
            ->and(collect($sync->details['missing_price'])->pluck('model')->all())->toContain('sabia-futuro')
            ->and((string) $sync->notice)->not->toContain(__('manager_ai.sync_notice_estimated_rate', ['rate' => '']));

        Http::assertSent(fn (Request $request) => $request->url() === 'https://chat.maritaca.ai/api/models'
            && $request->header('Authorization') === ['Key maritaca-test-key-000000']);
    });

    it('sem recarga com cotação: usa a cotação de referência e avisa no resultado', function () {
        $sync = runCatalogSync();

        expect((float) syncedRow('maritaca', 'sabia-4-br-sp')->input_usd_per_million)->toBe(round(6.5 / AiUsdBrlRate::FALLBACK, 6))
            ->and($sync->notice)->toContain(__('manager_ai.sync_notice_estimated_rate', [
                'rate' => Number::currency(AiUsdBrlRate::FALLBACK, 'BRL', app()->getLocale()),
            ]));
    });

    it('Azure: não lista pela API (deployment tem nome próprio) — só confere o preço dos cadastrados', function () {
        $this->catalog['azure/gpt-4o-mini'] = ['litellm_provider' => 'azure', 'mode' => 'chat', 'input_cost_per_token' => 0.165 / 1_000_000, 'output_cost_per_token' => 0.66 / 1_000_000];
        $deployment                         = catalogPrice('azure_openai', 'gpt-4o-mini', 1.0, 1.0);

        $sync = runCatalogSync();

        $azure = collect($sync->providerResults())->firstWhere('code', 'azure_openai');

        expect($azure['status'])->toBe(AiCatalogSync::PROVIDER_SKIPPED)
            ->and($azure['message'])->toBe(__('manager_ai.sync_provider_manual_models'))
            ->and((float) $deployment->fresh()->input_usd_per_million)->toBe(0.165)
            ->and((float) $deployment->fresh()->output_usd_per_million)->toBe(0.66);

        Http::assertNotSent(fn (Request $request) => str_contains($request->url(), 'openai.azure.com'));
    });
});
