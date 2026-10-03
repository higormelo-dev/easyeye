<?php

use App\Domains\AI\Models\AiModelPrice;
use App\Domains\AI\Services\AiProviderSettings;
use App\Enums\{ClientRule, SaasRule};
use App\Http\Controllers\Manager\AiProvidersController;
use App\Models\{Entity, User};
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\{JsonResponse, Request};
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    // mistral (UE — adequação) e maritaca (Sabiá "-br-sp" — Brasil) configurados,
    // sem exigência de registro de transferência (LGPD); openai sem credencial.
    config()->set('services.mistral.api_key', 'k');
    config()->set('ai.providers.mistral.model', 'mistral-large-latest');
    config()->set('services.maritaca.api_key', 'k');
    config()->set('ai.providers.maritaca.model', 'sabia-4-br-sp');
    config()->set('services.openai.api_key', null);

    $this->saas       = Entity::factory()->create(['is_client' => false, 'active' => true]);
    $this->user       = User::factory()->create();
    $this->entityUser = createEntityUser($this->saas, $this->user, SaasRule::Admin->value);
});

/** Invoca o update() do controller com sessão/usuário SaaS, fora do stack de middleware. */
function callProvidersUpdate(array $providers): JsonResponse
{
    test()->actingAs(test()->user);
    session(['selected_entity_id' => test()->saas->id]);

    $request = Request::create('/panel/manager/ai-providers', 'PATCH', ['providers' => $providers]);
    $request->setLaravelSession(app('session.store'));
    $request->setUserResolver(fn () => test()->user);

    return app(AiProvidersController::class)->update($request);
}

/** Invoca o update() com o payload NOVO de papéis explícitos. */
function callRolesUpdate(array $roles): JsonResponse
{
    test()->actingAs(test()->user);
    session(['selected_entity_id' => test()->saas->id]);

    $request = Request::create('/panel/manager/ai-providers', 'PATCH', $roles);
    $request->setLaravelSession(app('session.store'));
    $request->setUserResolver(fn () => test()->user);

    return app(AiProvidersController::class)->update($request);
}

describe('Manager\\AiProvidersController::update', function () {
    it('persiste a lista ordenada e registra auditoria', function () {
        $res = callProvidersUpdate(['mistral', 'maritaca']);

        expect($res->getStatusCode())->toBe(200)
            ->and(app(AiProviderSettings::class)->enabledCodes())->toBe(['mistral', 'maritaca']);

        expect(DB::table('audit_logs')->where('event', 'manager.ai_providers.update')->exists())->toBeTrue();
    });

    it('rejeita habilitar provedor sem credencial (422)', function () {
        $res = callProvidersUpdate(['openai']); // openai sem api_key

        expect($res->getStatusCode())->toBe(422);
    });

    it('rejeita lista vazia (422)', function () {
        $res = callProvidersUpdate([]);

        expect($res->getStatusCode())->toBe(422);
    });

    it('nega acesso quando a entidade não é SaaS', function () {
        $client     = Entity::factory()->create(['is_client' => true, 'active' => true]);
        $clientUser = User::factory()->create();
        createEntityUser($client, $clientUser, ClientRule::Admin->value);

        $this->actingAs($clientUser);
        session(['selected_entity_id' => $client->id]);

        $request = Request::create('/panel/manager/ai-providers', 'PATCH', ['providers' => ['mistral']]);
        $request->setLaravelSession(app('session.store'));
        $request->setUserResolver(fn () => $clientUser);

        expect(fn () => app(AiProvidersController::class)->update($request))
            ->toThrow(AuthorizationException::class);
    });
});

describe('Manager\\AiProvidersController::update — papéis explícitos', function () {
    it('persiste papéis primary/reviewer/adjudicator e deriva a lista habilitada', function () {
        config()->set('services.openai.api_key', 'k');
        config()->set('ai.providers.openai.model', 'gpt-4o');
        // OpenAI leva dado de paciente aos EUA: exige o mecanismo registrado (LGPD art. 33).
        app(AiProviderSettings::class)->setTransferRecord('openai', [
            'mechanism'     => 'standard_clauses', 'reference' => 'Aditivo CPC-ANPD', 'signed_at' => '2026-09-01',
            'registered_by' => null, 'registered_at' => null,
        ]);

        $res = callRolesUpdate(['primary' => 'mistral', 'reviewer' => 'openai', 'adjudicator' => 'maritaca']);

        expect($res->getStatusCode())->toBe(200);

        $settings = app(AiProviderSettings::class);
        expect($settings->roleAssignments())->toBe([
            'primary' => 'mistral', 'reviewer' => 'openai', 'adjudicator' => 'maritaca',
        ]);
        // Lista legada sincronizada na ordem papel→provedor.
        expect($settings->enabledCodes())->toBe(['mistral', 'openai', 'maritaca']);
    });

    it('permite só o principal (modo Economia)', function () {
        $res = callRolesUpdate(['primary' => 'mistral']);

        expect($res->getStatusCode())->toBe(200)
            ->and(app(AiProviderSettings::class)->enabledCodes())->toBe(['mistral']);
    });

    it('rejeita árbitro sem revisor (422)', function () {
        $res = callRolesUpdate(['primary' => 'mistral', 'adjudicator' => 'maritaca']);

        expect($res->getStatusCode())->toBe(422);
    });

    it('rejeita provedor sem credencial num papel (422)', function () {
        // openai está sem api_key no beforeEach.
        $res = callRolesUpdate(['primary' => 'openai']);

        expect($res->getStatusCode())->toBe(422);
    });

    it('rejeita o mesmo provedor em dois papéis (validação different)', function () {
        expect(fn () => callRolesUpdate(['primary' => 'mistral', 'reviewer' => 'mistral']))
            ->toThrow(ValidationException::class);
    });
});

describe('Manager\\AiProvidersController::update — modelo por provedor (painel)', function () {
    beforeEach(function () {
        AiModelPrice::query()->create([
            'provider'               => 'mistral',
            'model'                  => 'mistral-small-latest',
            'input_usd_per_million'  => 1.25,
            'output_usd_per_million' => 5.00,
            'effective_from'         => now()->subDay(),
            'active'                 => true,
        ]);
    });

    it('persiste o modelo escolhido e o serviço passa a usá-lo (sem .env)', function () {
        $res = callRolesUpdate([
            'primary' => 'mistral',
            'models'  => ['mistral' => 'mistral-small-latest'],
        ]);

        expect($res->getStatusCode())->toBe(200);

        $settings = app(AiProviderSettings::class);
        expect($settings->panelModels())->toBe(['mistral' => 'mistral-small-latest'])
            ->and($settings->model('mistral'))->toBe('mistral-small-latest');
    });

    it('rejeita modelo sem preço cadastrado (422)', function () {
        $res = callRolesUpdate([
            'primary' => 'mistral',
            'models'  => ['mistral' => 'modelo-fantasma'],
        ]);

        expect($res->getStatusCode())->toBe(422);
    });

    it('modelo vazio volta ao fallback do env', function () {
        app(AiProviderSettings::class)->setModels(['mistral' => 'mistral-small-latest']);

        $res = callRolesUpdate([
            'primary' => 'mistral',
            'models'  => ['mistral' => ''],
        ]);

        expect($res->getStatusCode())->toBe(200)
            ->and(app(AiProviderSettings::class)->panelModels())->toBe([])
            ->and(app(AiProviderSettings::class)->model('mistral'))->toBe('mistral-large-latest');
    });
});
