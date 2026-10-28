<?php

use App\DTOs\Billing\GatewayCallContext;
use App\Enums\Billing\CredentialScope;
use App\Models\Billing\{Gateway, GatewayCredential};
use App\Models\Entity;
use App\Services\Billing\{GatewayCredentialResolver, GatewayRegistry};
use App\Support\TenantContext;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\{DB, Http};
use Illuminate\Support\Str;

/**
 * Os gateways são só do dono do SaaS: toda cobrança à clínica usa a
 * credencial global do manager, mesmo com o id da clínica no contexto.
 * Clínica não tem gateway próprio — linha com entity_id (ou scope 'tenant',
 * resto da funcionalidade removida) nunca é usada.
 */
function scopeAsaasGateway(): Gateway
{
    return Gateway::query()->firstOrCreate(['code' => 'asaas'], ['name' => 'Asaas', 'active' => true]);
}

/** Linha "por clínica" gravada direto no banco (o model já não tem como). */
function scopeInsertClinicRow(Gateway $gateway, ?string $entityId, string $scope, string $secret): void
{
    DB::table('gateway_credentials')->insert([
        'id'          => (string) Str::uuid(),
        'gateway_id'  => $gateway->id,
        'entity_id'   => $entityId,
        'scope'       => $scope,
        'credentials' => encrypt(json_encode(['secret' => $secret]), false),
        'active'      => true,
        'created_at'  => now()->addMinute(),
        'updated_at'  => now()->addMinute(),
    ]);
}

it('cobrança da assinatura usa a credencial global do manager, não a da clínica', function () {
    $clinic = Entity::factory()->create(['is_client' => true]);
    config(['billing.gateways.asaas.secret' => 'env_secret_nao_usar']);

    $gateway = scopeAsaasGateway();

    GatewayCredential::query()->create([
        'gateway_id'  => $gateway->id,
        'scope'       => CredentialScope::Global->value,
        'entity_id'   => null,
        'credentials' => ['secret' => 'manager_secret_global'],
        'active'      => true,
    ]);

    // Mesmo com uma linha "da clínica" mais nova no banco.
    scopeInsertClinicRow($gateway, $clinic->id, 'tenant', 'clinic_secret_nao_usar');

    Http::fake(['*' => Http::response(['object' => 'list', 'data' => [], 'totalCount' => 0], 200)]);

    app(GatewayRegistry::class)->get('asaas')
        ->withContext(new GatewayCallContext('corr-1', (string) $clinic->id))
        ->fetchPayment('pay_123');

    Http::assertSent(fn (Request $r) => $r->hasHeader('access_token', 'manager_secret_global'));
});

it('o resolvedor ignora qualquer linha com entity_id ou scope diferente de global', function () {
    $clinic  = Entity::factory()->create(['is_client' => true]);
    $gateway = scopeAsaasGateway();

    scopeInsertClinicRow($gateway, $clinic->id, 'tenant', 'clinic_tenant');
    scopeInsertClinicRow($gateway, $clinic->id, 'global', 'clinic_global_scope');
    scopeInsertClinicRow($gateway, null, 'tenant', 'tenant_sem_entidade');

    $resolver = new GatewayCredentialResolver(0);

    expect($resolver->resolveSecret('asaas'))->toBeNull()
        ->and($resolver->resolveExtra('asaas', 'secret'))->toBeNull()
        ->and($resolver->resolveWebhookSecret('asaas'))->toBeNull();

    GatewayCredential::query()->create([
        'gateway_id'     => $gateway->id,
        'scope'          => CredentialScope::Global->value,
        'entity_id'      => null,
        'credentials'    => ['secret' => 'saas_global', 'public_key' => 'pk_saas'],
        'webhook_secret' => 'whsec_saas',
        'active'         => true,
    ]);

    expect($resolver->resolveSecret('asaas'))->toBe('saas_global')
        ->and($resolver->resolveExtra('asaas', 'public_key'))->toBe('pk_saas')
        ->and($resolver->resolveWebhookSecret('asaas'))->toBe('whsec_saas');
});

it('sem credencial global no banco, o gateway cai no .env (nunca na da clínica)', function () {
    $clinic = Entity::factory()->create(['is_client' => true]);
    config(['billing.gateways.asaas.secret' => 'env_secret_saas']);

    scopeInsertClinicRow(scopeAsaasGateway(), $clinic->id, 'tenant', 'clinic_secret_nao_usar');

    Http::fake(['*' => Http::response(['object' => 'list', 'data' => [], 'totalCount' => 0], 200)]);

    app(GatewayRegistry::class)->get('asaas')
        ->withContext(new GatewayCallContext('corr-2', (string) $clinic->id))
        ->fetchPayment('pay_456');

    Http::assertSent(fn (Request $r) => $r->hasHeader('access_token', 'env_secret_saas'));
});

it('credencial criada com uma clínica ativa no contexto continua global (sem auto-set de entity_id)', function () {
    $clinic = Entity::factory()->create(['is_client' => true]);

    $credential = app(TenantContext::class)->runAs($clinic->id, fn () => GatewayCredential::query()->create([
        'gateway_id'  => scopeAsaasGateway()->id,
        'scope'       => CredentialScope::Global->value,
        'credentials' => ['secret' => 'saas_global'],
        'active'      => true,
    ]));

    expect($credential->fresh()->entity_id)->toBeNull()
        ->and(app(TenantContext::class)->runAs($clinic->id, fn () => (new GatewayCredentialResolver(0))->resolveSecret('asaas')))
        ->toBe('saas_global');
});

it('CredentialScope só tem o escopo global', function () {
    expect(CredentialScope::cases())->toBe([CredentialScope::Global])
        ->and(CredentialScope::tryFrom('tenant'))->toBeNull();
});
