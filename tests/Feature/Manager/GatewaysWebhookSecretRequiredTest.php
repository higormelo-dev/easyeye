<?php

/**
 * BUGFIX (revisao de seguranca): GatewaysController::storeCredential aceitava
 * 'webhook_secret' => nullable mesmo para gateways com supports_webhooks=true,
 * permitindo salvar uma credencial de gateway webhook-capable sem segredo — o
 * que deixava validateWebhookSignature() sem nada para validar (fail-open).
 * Agora webhook_secret é obrigatório sempre que o gateway suporta webhooks.
 */

use App\Models\Billing\{Gateway, GatewayCredential};
use App\Models\{Entity, User};
use App\Services\Billing\GatewayCredentialResolver;

beforeEach(function () {
    $this->saas  = Entity::factory()->create(['is_client' => false, 'active' => true]);
    $this->admin = User::factory()->create();
    createEntityUser($this->saas, $this->admin, 'admin');
});

function gatewaysManagerAdminSession(Entity $saas): array
{
    return [
        'selected_entity_id'        => $saas->id,
        'selected_entity_is_client' => false,
        'selected_entity_user_rule' => 'admin',
    ];
}

test('storeCredential rejeita gateway com supports_webhooks sem webhook_secret', function () {
    $gateway = Gateway::create([
        'code'                      => 'test_gw_webhook',
        'name'                      => 'Test Gateway Webhook',
        'active'                    => true,
        'is_default'                => false,
        'supports_subscriptions'    => true,
        'supports_one_time_charges' => true,
        'supports_refunds'          => false,
        'supports_webhooks'         => true,
        'priority'                  => 10,
    ]);

    $response = $this->actingAs($this->admin)
        ->withSession(gatewaysManagerAdminSession($this->saas))
        ->postJson(route('manager.gateways.credentials.store', $gateway), [
            'secret' => 'super-secret-key',
            'reason' => 'Rotação programada de credenciais do gateway de testes.',
        ]);

    $response->assertStatus(422)
        ->assertJsonValidationErrors(['webhook_secret']);
});

test('storeCredential aceita gateway sem supports_webhooks mesmo sem webhook_secret', function () {
    $gateway = Gateway::create([
        'code'                      => 'test_gw_no_webhook',
        'name'                      => 'Test Gateway No Webhook',
        'active'                    => true,
        'is_default'                => false,
        'supports_subscriptions'    => true,
        'supports_one_time_charges' => true,
        'supports_refunds'          => false,
        'supports_webhooks'         => false,
        'priority'                  => 11,
    ]);

    $response = $this->actingAs($this->admin)
        ->withSession(gatewaysManagerAdminSession($this->saas))
        ->postJson(route('manager.gateways.credentials.store', $gateway), [
            'secret' => 'super-secret-key',
            'reason' => 'Rotação programada de credenciais do gateway de testes.',
        ]);

    $response->assertOk();
});

function gatewaysInfinitePay(): Gateway
{
    return Gateway::query()->updateOrCreate(['code' => 'infinitepay'], [
        'name'                      => 'InfinitePay',
        'active'                    => true,
        'is_default'                => false,
        'supports_subscriptions'    => false,
        'supports_one_time_charges' => true,
        'supports_refunds'          => false,
        'supports_webhooks'         => true,
        'priority'                  => 12,
    ]);
}

test('InfinitePay: a credencial é a InfiniteTag (handle) — sem token nem segredo de webhook', function () {
    $gateway = gatewaysInfinitePay();

    $this->actingAs($this->admin)
        ->withSession(gatewaysManagerAdminSession($this->saas))
        ->postJson(route('manager.gateways.credentials.store', $gateway), [
            'handle' => '$minha.loja',
            'reason' => 'Cadastro da InfiniteTag da conta InfinitePay do EasyEye.',
        ])
        ->assertOk();

    $credential = GatewayCredential::query()->where('gateway_id', $gateway->id)->where('active', true)->sole();

    expect($credential->credentials)->toBe(['handle' => 'minha.loja'])
        ->and($credential->webhook_secret)->toBeNull()
        ->and(app(GatewayCredentialResolver::class)->resolveExtra('infinitepay', 'handle'))->toBe('minha.loja');
});

test('InfinitePay: sem handle (ou com handle inválido) é recusado', function (array $payload, string $field) {
    $gateway = gatewaysInfinitePay();

    $this->actingAs($this->admin)
        ->withSession(gatewaysManagerAdminSession($this->saas))
        ->postJson(route('manager.gateways.credentials.store', $gateway), [
            ...$payload,
            'reason' => 'Cadastro da InfiniteTag da conta InfinitePay do EasyEye.',
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors([$field]);
})->with([
    'sem handle'      => [['secret' => 'qualquer-token'], 'handle'],
    'handle inválido' => [['handle' => 'minha loja!'], 'handle'],
]);

test('outros gateways não aceitam o campo handle', function () {
    $gateway = Gateway::query()->updateOrCreate(['code' => 'test_gw_handle'], [
        'name'                      => 'Test Gateway Handle',
        'active'                    => true,
        'is_default'                => false,
        'supports_subscriptions'    => true,
        'supports_one_time_charges' => true,
        'supports_refunds'          => false,
        'supports_webhooks'         => false,
        'priority'                  => 13,
    ]);

    $this->actingAs($this->admin)
        ->withSession(gatewaysManagerAdminSession($this->saas))
        ->postJson(route('manager.gateways.credentials.store', $gateway), [
            'secret' => 'super-secret-key',
            'handle' => 'loja',
            'reason' => 'Rotação programada de credenciais do gateway de testes.',
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['handle']);
});
