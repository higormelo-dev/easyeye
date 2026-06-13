<?php

use App\Domains\AI\Services\AiProviderSettings;
use App\Enums\{ClientRule, SaasRule};
use App\Http\Controllers\Manager\AiProvidersController;
use App\Models\{Entity, User};
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\{JsonResponse, Request};
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    // gemini e anthropic configurados; openai sem credencial.
    config()->set('services.gemini.api_key', 'k');
    config()->set('ai.providers.gemini.model', 'gemini-2.0-flash');
    config()->set('services.anthropic.api_key', 'k');
    config()->set('ai.providers.anthropic.model', 'claude-sonnet-4-5');
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

describe('Manager\\AiProvidersController::update', function () {
    it('persiste a lista ordenada e registra auditoria', function () {
        $res = callProvidersUpdate(['gemini', 'anthropic']);

        expect($res->getStatusCode())->toBe(200)
            ->and(app(AiProviderSettings::class)->enabledCodes())->toBe(['gemini', 'anthropic']);

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

        $request = Request::create('/panel/manager/ai-providers', 'PATCH', ['providers' => ['gemini']]);
        $request->setLaravelSession(app('session.store'));
        $request->setUserResolver(fn () => $clientUser);

        expect(fn () => app(AiProvidersController::class)->update($request))
            ->toThrow(AuthorizationException::class);
    });
});
