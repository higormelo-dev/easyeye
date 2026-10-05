<?php

use App\DTOs\Billing\GatewayCallContext;
use App\Enums\Billing\CredentialScope;
use App\Models\Billing\{Gateway, GatewayCredential};
use App\Models\Entity;
use App\Services\Billing\GatewayRegistry;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

/**
 * Assinatura do EasyEye cobra a clínica com a credencial do SaaS (manager),
 * mesmo com o id da clínica no contexto. Antes, o contexto com a clínica
 * fazia o resolvedor procurar credencial "tenant" e ignorar a do manager.
 */
it('cobrança da assinatura usa a credencial global do manager, não a da clínica', function () {
    $clinic = Entity::factory()->create(['is_client' => true]);
    config(['billing.gateways.asaas.secret' => 'env_secret_nao_usar']);

    $gateway = Gateway::query()->firstOrCreate(['code' => 'asaas'], ['name' => 'Asaas', 'active' => true]);

    GatewayCredential::query()->create([
        'gateway_id'  => $gateway->id,
        'scope'       => CredentialScope::Global->value,
        'entity_id'   => null,
        'credentials' => ['secret' => 'manager_secret_global'],
        'active'      => true,
    ]);

    Http::fake(['*' => Http::response(['object' => 'list', 'data' => [], 'totalCount' => 0], 200)]);

    app(GatewayRegistry::class)->get('asaas')
        ->withContext(new GatewayCallContext('corr-1', (string) $clinic->id))
        ->fetchPayment('pay_123');

    Http::assertSent(fn (Request $r) => $r->hasHeader('access_token', 'manager_secret_global'));
});
