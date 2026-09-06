<?php

/**
 * BUGFIX (revisao de seguranca): GatewaysController::storeCredential aceitava
 * 'webhook_secret' => nullable mesmo para gateways com supports_webhooks=true,
 * permitindo salvar uma credencial de gateway webhook-capable sem segredo — o
 * que deixava validateWebhookSignature() sem nada para validar (fail-open).
 * Agora webhook_secret é obrigatório sempre que o gateway suporta webhooks.
 */

use App\Models\Billing\Gateway;
use App\Models\{Entity, User};

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
