<?php

/**
 * Manager → Gateways é exclusivo do dono do SaaS: os gateways cobram as
 * clínicas (assinatura e pacotes de IA).
 * O "Acesso por Clínica" (entity_gateway_access) e as credenciais por clínica
 * foram removidos.
 */

use App\Models\Billing\{Gateway, GatewayCredential};
use App\Models\{Entity, User};
use Illuminate\Support\Facades\{DB, Http, Route, Schema};
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;

beforeEach(function () {
    $this->saas  = Entity::factory()->create(['is_client' => false, 'active' => true]);
    $this->admin = User::factory()->create();
    createEntityUser($this->saas, $this->admin, 'admin');
});

function saasOnlySession(Entity $saas): array
{
    return [
        'selected_entity_id'        => $saas->id,
        'selected_entity_is_client' => false,
        'selected_entity_user_rule' => 'admin',
    ];
}

function saasOnlyGateway(string $code, string $name, int $priority): Gateway
{
    return Gateway::query()->updateOrCreate(['code' => $code], [
        'name'                      => $name,
        'active'                    => true,
        'is_default'                => false,
        'supports_subscriptions'    => true,
        'supports_one_time_charges' => true,
        'supports_refunds'          => true,
        'supports_webhooks'         => true,
        'priority'                  => $priority,
    ]);
}

function saasOnlyMigration(string $file): object
{
    return include database_path("migrations/{$file}");
}

test('rotas de acesso por clínica não existem mais', function () {
    $gateway = saasOnlyGateway('asaas', 'Asaas', 20);
    $clinic  = Entity::factory()->create(['is_client' => true]);

    expect(Route::has('manager.gateways.entity-access'))->toBeFalse()
        ->and(Route::has('manager.gateways.entity-access.toggle'))->toBeFalse();

    $this->actingAs($this->admin)
        ->withSession(saasOnlySession($this->saas))
        ->getJson("/panel/manager/gateways/{$gateway->id}/entity-access")
        ->assertNotFound();

    $this->actingAs($this->admin)
        ->withSession(saasOnlySession($this->saas))
        ->patchJson("/panel/manager/gateways/{$gateway->id}/entity-access/{$clinic->id}")
        ->assertNotFound();
});

test('a tabela entity_gateway_access foi removida', function () {
    expect(Schema::hasTable('entity_gateway_access'))->toBeFalse()
        ->and(class_exists('App\\Models\\Billing\\EntityGatewayAccess'))->toBeFalse()
        ->and(method_exists(Gateway::class, 'entityAccess'))->toBeFalse();
});

test('a tela do manager não expõe nada por clínica e explica que os gateways são do EasyEye', function () {
    saasOnlyGateway('asaas', 'Asaas', 20);
    saasOnlyGateway('stripe_br', 'Stripe Brasil', 50);
    saasOnlyGateway('pagbank', 'PagBank', 60);

    // Capacidades vêm dos métodos da classe — nenhuma chamada HTTP (o
    // PagBank buscaria a chave pública na API se cardCheckoutConfig fosse usado).
    Http::preventStrayRequests();

    $response = $this->actingAs($this->admin)
        ->withSession(saasOnlySession($this->saas))
        ->get(route('manager.gateways.index'))
        ->assertOk();

    $response->assertInertia(
        fn (AssertableInertia $page) => $page
            ->component('Panel/Manager/Gateways/Index')
            ->missing('t.ctx_tenant_title')
            ->missing('t.ctx_tenant_desc')
            ->missing('t.btn_entity_access')
            ->missing('t.modal_ea_title')
            ->missing('t.clinics_with_access')
            ->where('t.title', __('gateways.title'))
            ->where('gateways', fn ($rows) => collect($rows)->every(fn ($row) => ! array_key_exists('entity_access_url', (array) $row)
                && ! array_key_exists('entities_with_access_count', (array) $row)
                && ! array_key_exists('clinics_label', (array) $row))),
    );

    $html = $response->getContent();
    expect($html)->not->toContain('entity-access')
        ->and($html)->not->toContain('Tenant Payment')
        ->and(__('gateways.subtitle'))->toContain('pacotes de créditos de IA')
        ->and(__('gateways.subtitle'))->not->toContain('pacientes');
});

test('a tela mostra o que cada gateway suporta hoje na EasyEye', function () {
    saasOnlyGateway('asaas', 'Asaas', 20);
    saasOnlyGateway('stripe_br', 'Stripe Brasil', 50);
    saasOnlyGateway('pagbank', 'PagBank', 60);
    saasOnlyGateway('infinitepay', 'InfinitePay', 30);
    saasOnlyGateway('gw_sem_classe', 'Sem classe', 99);

    Http::preventStrayRequests();

    $rows = collect(
        $this->actingAs($this->admin)
            ->withSession(saasOnlySession($this->saas))
            ->get(route('manager.gateways.index'))
            ->assertOk()
            ->viewData('page')['props']['gateways'],
    )->keyBy('code');

    expect($rows['asaas']['capabilities'])->toMatchArray([
        'transparent'       => ['pix', 'boleto'],
        'card_link'         => true,
        'max_installments'  => null,
        'native_recurrence' => true,
    ])
        ->and($rows['stripe_br']['capabilities'])->toMatchArray([
            'transparent'        => ['pix', 'boleto', 'credit_card'],
            'card_link'          => true,
            'max_installments'   => 1,
            'saved_card_renewal' => true,
            'native_recurrence'  => false,
        ])
        ->and($rows['pagbank']['capabilities'])->toMatchArray([
            'max_installments' => 12,
            'card_replacement' => false,
        ])
        ->and($rows['infinitepay']['capabilities'])->toMatchArray([
            'transparent' => [],
            'card_link'   => true,
        ])
        ->and($rows['gw_sem_classe']['capabilities'])->toBeNull();
});

test('credencial salva no manager é sempre global', function () {
    $gateway = saasOnlyGateway('asaas', 'Asaas', 20);

    $this->actingAs($this->admin)
        ->withSession(saasOnlySession($this->saas))
        ->postJson(route('manager.gateways.credentials.store', $gateway), [
            'secret'         => 'aact_secret_do_saas',
            'webhook_secret' => 'token-webhook-asaas-0123456789abcdef',
            'reason'         => 'Cadastro da credencial do Asaas do dono do EasyEye.',
        ])
        ->assertOk();

    $credential = GatewayCredential::query()->where('gateway_id', $gateway->id)->sole();

    expect($credential->entity_id)->toBeNull()
        ->and($credential->scope->value)->toBe('global');
});

test('migration de limpeza apaga as credenciais por clínica e mantém a global', function () {
    $gateway = saasOnlyGateway('asaas', 'Asaas', 20);
    $clinic  = Entity::factory()->create(['is_client' => true]);

    $insert = function (?string $entityId, string $scope, bool $deleted = false) use ($gateway): string {
        $id = (string) Str::uuid();

        DB::table('gateway_credentials')->insert([
            'id'          => $id,
            'gateway_id'  => $gateway->id,
            'entity_id'   => $entityId,
            'scope'       => $scope,
            'credentials' => encrypt(json_encode(['secret' => "s-{$id}"]), false),
            'active'      => ! $deleted,
            'deleted_at'  => $deleted ? now() : null,
            'created_at'  => now(),
            'updated_at'  => now(),
        ]);

        return $id;
    };

    $global = $insert(null, 'global');
    $insert($clinic->id, 'tenant');
    $insert($clinic->id, 'global');
    $insert(null, 'tenant');
    $insert($clinic->id, 'tenant', deleted: true);

    saasOnlyMigration('2026_10_11_000100_delete_tenant_gateway_credentials.php')->up();

    expect(DB::table('gateway_credentials')->pluck('id')->all())->toBe([$global]);

    // down() é no-op documentado: nada volta.
    saasOnlyMigration('2026_10_11_000100_delete_tenant_gateway_credentials.php')->down();
    expect(DB::table('gateway_credentials')->count())->toBe(1);
});

test('migration do acesso por clínica: down recria o schema original e up derruba de novo', function () {
    $migration = saasOnlyMigration('2026_10_11_000000_drop_entity_gateway_access_table.php');

    $migration->down();
    expect(Schema::hasTable('entity_gateway_access'))->toBeTrue()
        ->and(Schema::hasColumns('entity_gateway_access', ['id', 'entity_id', 'gateway_id', 'enabled', 'notes', 'created_by', 'updated_by']))->toBeTrue();

    $migration->up();
    expect(Schema::hasTable('entity_gateway_access'))->toBeFalse();
});
