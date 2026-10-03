<?php

use App\Domains\AI\Models\AiModelPrice;
use App\Enums\{ClientRule, SaasRule};
use App\Http\Controllers\Manager\AiModelPricesController;
use App\Models\{Entity, User};
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\{JsonResponse, Request};
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    $this->saas       = Entity::factory()->create(['is_client' => false, 'active' => true]);
    $this->user       = User::factory()->create();
    $this->entityUser = createEntityUser($this->saas, $this->user, SaasRule::Admin->value);
});

function callPrices(string $method, string $action, array $payload = [], ?AiModelPrice $price = null): JsonResponse
{
    test()->actingAs(test()->user);
    session(['selected_entity_id' => test()->saas->id]);

    $request = Request::create('/panel/manager/ai-model-prices', $method, $payload);
    $request->setLaravelSession(app('session.store'));
    $request->setUserResolver(fn () => test()->user);

    $controller = app(AiModelPricesController::class);

    return $price !== null
        ? $controller->{$action}($request, $price)
        : $controller->{$action}($request);
}

describe('Manager\\AiModelPricesController', function () {
    it('cadastra modelo com preço, audita e o modelo vira elegível no seletor', function () {
        $res = callPrices('POST', 'store', [
            'provider'               => 'openai',
            'model'                  => 'gpt-4o-mini',
            'input_usd_per_million'  => 0.15,
            'output_usd_per_million' => 0.60,
        ]);

        expect($res->getStatusCode())->toBe(200);
        expect(AiModelPrice::query()->where('model', 'gpt-4o-mini')->where('active', true)->exists())->toBeTrue();
        expect(DB::table('audit_logs')->where('event', 'manager.ai_model_prices.store')->exists())->toBeTrue();
    });

    it('rejeita modelo duplicado para o mesmo provedor (422)', function () {
        AiModelPrice::query()->create([
            'provider'              => 'openai', 'model' => 'gpt-4o-mini',
            'input_usd_per_million' => 0.15, 'output_usd_per_million' => 0.60,
            'effective_from'        => now(), 'active' => true,
        ]);

        $res = callPrices('POST', 'store', [
            'provider'               => 'openai',
            'model'                  => 'gpt-4o-mini',
            'input_usd_per_million'  => 0.20,
            'output_usd_per_million' => 0.80,
        ]);

        expect($res->getStatusCode())->toBe(422);
    });

    it('edita preços e desativa (some do seletor, histórico preservado)', function () {
        $price = AiModelPrice::query()->create([
            'provider'              => 'gemini', 'model' => 'gemini-1.5-flash',
            'input_usd_per_million' => 0.075, 'output_usd_per_million' => 0.30,
            'effective_from'        => now(), 'active' => true,
        ]);

        $res = callPrices('PATCH', 'update', [
            'input_usd_per_million'  => 0.10,
            'output_usd_per_million' => 0.40,
            'active'                 => false,
        ], $price);

        expect($res->getStatusCode())->toBe(200);
        $price->refresh();
        expect((float) $price->input_usd_per_million)->toBe(0.10)
            ->and($price->active)->toBeFalse();
    });

    it('nega acesso fora da entidade SaaS', function () {
        $client     = Entity::factory()->create(['is_client' => true, 'active' => true]);
        $clientUser = User::factory()->create();
        createEntityUser($client, $clientUser, ClientRule::Admin->value);

        $this->actingAs($clientUser);
        session(['selected_entity_id' => $client->id]);

        $request = Request::create('/panel/manager/ai-model-prices', 'POST', []);
        $request->setLaravelSession(app('session.store'));
        $request->setUserResolver(fn () => $clientUser);

        expect(fn () => app(AiModelPricesController::class)->store($request))
            ->toThrow(AuthorizationException::class);
    });
});

describe('Manager\\AiModelPricesController — trava de preço (sincronização)', function () {
    it('preço digitado à mão nasce travado e manual; aceita ids com "/" e ":" (Groq)', function () {
        $res = callPrices('POST', 'store', [
            'provider'               => 'groq',
            'model'                  => 'meta-llama/llama-3.3-70b-instruct:free',
            'input_usd_per_million'  => 0,
            'output_usd_per_million' => 0,
        ]);

        $row = AiModelPrice::query()->where('model', 'meta-llama/llama-3.3-70b-instruct:free')->sole();

        expect($res->getStatusCode())->toBe(200)
            ->and($row->price_locked)->toBeTrue()
            ->and($row->source)->toBe(AiModelPrice::SOURCE_MANUAL)
            ->and(collect($res->getData(true)['prices'])->firstWhere('id', $row->id))
            ->toMatchArray(['price_locked' => true, 'source' => 'manual', 'provider_label' => 'Groq']);
    });

    it('o admin pode pedir para o catálogo manter o preço atualizado (sem trava)', function () {
        callPrices('POST', 'store', [
            'provider'              => 'mistral', 'model' => 'mistral-small-latest', 'price_locked' => false,
            'input_usd_per_million' => 0.1, 'output_usd_per_million' => 0.3,
        ]);

        expect(AiModelPrice::query()->where('model', 'mistral-small-latest')->sole()->price_locked)->toBeFalse();
    });

    it('mudar o preço à mão trava; só ativar/desativar não mexe na trava; o admin destrava pelo modal', function () {
        $price = AiModelPrice::factory()->create(['model' => 'gpt-4o', 'input_usd_per_million' => 2.5, 'output_usd_per_million' => 10, 'price_locked' => false]);
        $base  = ['input_usd_per_million' => 2.5, 'output_usd_per_million' => 10, 'reasoning_usd_per_million' => null, 'tool_call_usd' => null];

        callPrices('PATCH', 'update', [...$base, 'active' => false], $price);
        expect($price->fresh()->price_locked)->toBeFalse();

        callPrices('PATCH', 'update', [...$base, 'input_usd_per_million' => 2.0, 'active' => true], $price);
        expect($price->fresh()->price_locked)->toBeTrue();

        callPrices('PATCH', 'update', [...$base, 'input_usd_per_million' => 2.0, 'active' => true, 'price_locked' => false], $price);
        expect($price->fresh()->price_locked)->toBeFalse();
    });

    it('[SEGURANÇA] id de modelo com caracteres fora do padrão é recusado', function () {
        expect(fn () => callPrices('POST', 'store', [
            'provider' => 'openai', 'model' => 'gpt"><script>', 'input_usd_per_million' => 1, 'output_usd_per_million' => 1,
        ]))->toThrow(ValidationException::class);
    });
});
