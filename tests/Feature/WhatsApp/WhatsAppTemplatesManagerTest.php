<?php

declare(strict_types=1);

use App\Enums\SaasRule;
use App\Models\{Entity, User};
use App\Models\WhatsApp\WhatsAppSetting;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\{Cache, DB, Http};
use Illuminate\Support\Str;

/**
 * Manager → WhatsApp → Modelos (conta comum da Gupshup): estado de cada modelo no app e criação por API,
 * um por chamada. Http::fake — nada sai. Só o Admin do SaaS; nunca recria quem já existe.
 */
beforeEach(function () {
    Http::preventStrayRequests();
    Cache::flush();

    config([
        'app.url'                        => 'https://easyeye.app',
        'whatsapp.driver'                => 'gupshup',
        'whatsapp.gupshup.auth_mode'     => '',
        'whatsapp.gupshup.api_base_url'  => 'https://api.gupshup.io',
        'whatsapp.gupshup.api_key'       => 'sk_conta_comum',
        'whatsapp.gupshup.app_name'      => 'EasyEyeApp',
        'whatsapp.gupshup.source_number' => '+55 61 99999-0000',
        'whatsapp.template_languages'    => ['pt_BR'],
    ]);
});

function wtSaas($test, string $rule = SaasRule::Admin->value): void
{
    $saas = Entity::factory()->create(['is_client' => false, 'active' => true]);
    $user = User::factory()->create();
    createEntityUser($saas, $user, $rule);

    $test->actingAs($user);
    session(['selected_entity_id' => $saas->id]);
}

function wtGlobal(): WhatsAppSetting
{
    return WhatsAppSetting::create([
        'entity_id'     => null,
        'app_id'        => 'app-123',
        'webhook_token' => 'wt-token-' . Str::random(30),
        'active'        => true,
    ]);
}

/** Lista de modelos do app (GET) + resposta do POST de criação (padrão: criado, PENDING). */
function wtStub(array $existing = [], ?Closure $create = null): void
{
    Http::fake(function (Request $request) use ($existing, $create) {
        if ($request->method() === 'GET') {
            return Http::response(['status' => 'success', 'templates' => $existing]);
        }

        return $create ? $create($request) : Http::response(['status' => 'success', 'template' => ['id' => 'tpl-novo', 'status' => 'PENDING']]);
    });
}

/** @return list<Request> POSTs de criação enviados. */
function wtPosts(): array
{
    return Http::recorded(fn (Request $request) => $request->method() === 'POST')->map(fn (array $pair) => $pair[0])->values()->all();
}

function wtCreate($test, string $key = 'connection_test', string $language = 'pt_BR')
{
    return $test->postJson(route('manager.whatsapp.templates.create'), ['key' => $key, 'language' => $language]);
}

it('[SEGURANÇA] só o Admin do SaaS lê o estado e cria modelos (Support, Financial e User recebem 403)', function () {
    wtGlobal();

    foreach ([SaasRule::Support, SaasRule::Financial, SaasRule::User] as $rule) {
        wtSaas($this, $rule->value);

        $this->getJson(route('manager.whatsapp.templates.status'))->assertForbidden();
        wtCreate($this)->assertForbidden();
    }

    Http::assertNothingSent();
});

describe('estado dos modelos', function () {
    it('devolve estado e motivo por nome e idioma, só com GET e sem expor a chave', function () {
        wtSaas($this);
        wtGlobal();
        wtStub([
            ['id' => '1', 'elementName' => 'easyeye_codigo_verificacao', 'languageCode' => 'pt_BR', 'status' => 'FAILED', 'reason' => 'sem permissão'],
            ['id' => '2', 'elementName' => 'easyeye_teste_conexao', 'languageCode' => 'pt_BR', 'status' => 'APPROVED'],
        ]);

        $response = $this->getJson(route('manager.whatsapp.templates.status'))->assertOk();

        expect($response->json())->toBe([
            'ok'        => true,
            'templates' => [
                'easyeye_codigo_verificacao|pt_BR' => ['status' => 'FAILED', 'reason' => 'sem permissão'],
                'easyeye_teste_conexao|pt_BR'      => ['status' => 'APPROVED', 'reason' => ''],
            ],
        ]);

        Http::assertSentCount(1);
        Http::assertSent(fn (Request $r) => $r->method() === 'GET' && $r->hasHeader('apikey', 'sk_conta_comum'));
        expect($response->getContent())->not->toContain('sk_conta_comum');
    });

    it('sem app global: 422 pedindo o cadastro, sem chamar a Gupshup', function () {
        wtSaas($this);

        $this->getJson(route('manager.whatsapp.templates.status'))
            ->assertStatus(422)
            ->assertJson(['ok' => false, 'error' => __('whatsapp.no_app')]);

        Http::assertNothingSent();
    });

    it('fora da conta comum (mock): 422 sem chamar a Gupshup', function () {
        config(['whatsapp.driver' => 'mock']);
        wtSaas($this);
        wtGlobal();

        $this->getJson(route('manager.whatsapp.templates.status'))
            ->assertStatus(422)
            ->assertJson(['ok' => false, 'error' => __('whatsapp.template_api.only_self_serve')]);

        Http::assertNothingSent();
    });

    it('erro da Gupshup ao listar: 422 com a mensagem', function () {
        wtSaas($this);
        wtGlobal();
        Http::fake(['api.gupshup.io/*' => Http::response(['status' => 'error', 'message' => 'Authentication Failed'], 401)]);

        $this->getJson(route('manager.whatsapp.templates.status'))
            ->assertStatus(422)
            ->assertJsonPath('ok', false)
            ->assertJsonPath('error', fn (string $error) => str_contains($error, 'Gupshup HTTP 401'));
    });
});

describe('criar um modelo', function () {
    it('envia o modelo do catálogo, responde PENDING e registra na trilha sem segredo', function () {
        wtSaas($this);
        $setting = wtGlobal();
        wtStub();

        wtCreate($this)->assertOk()->assertJson(['ok' => true, 'status' => 'PENDING', 'message' => __('whatsapp.template_api.created')]);

        $posts = wtPosts();

        expect($posts)->toHaveCount(1)
            ->and($posts[0]->url())->toBe('https://api.gupshup.io/wa/app/app-123/template')
            ->and($posts[0]->hasHeader('apikey', 'sk_conta_comum'))->toBeTrue()
            ->and($posts[0]->data())->toMatchArray([
                'elementName'  => 'easyeye_teste_conexao',
                'languageCode' => 'pt_BR',
                'category'     => 'UTILITY',
                'templateType' => 'TEXT',
                'example'      => 'Este é um teste de envio do [EasyEye]. Se você recebeu, o WhatsApp está configurado corretamente.',
            ]);

        $audit = DB::table('audit_logs')->where('event', 'manager.whatsapp_template.submitted')->sole();

        expect($audit->auditable_id)->toBe((string) $setting->id)
            ->and($audit->new_values)->toContain('easyeye_teste_conexao')->toContain('"ok":true')->toContain('PENDING')
            ->and($audit->new_values)->not->toContain('sk_conta_comum');
    });

    it('nunca recria: modelo que já existe, em qualquer estado, responde 409 sem POST nem trilha', function () {
        wtSaas($this);
        wtGlobal();
        wtStub([['id' => '1', 'elementName' => 'easyeye_teste_conexao', 'languageCode' => 'pt_BR', 'status' => 'FAILED']]);

        wtCreate($this)
            ->assertStatus(409)
            ->assertJson(['ok' => false, 'status' => 'FAILED', 'error' => __('whatsapp.template_api.exists', ['status' => 'FAILED'])]);

        expect(wtPosts())->toBe([]);
        expect(DB::table('audit_logs')->where('event', 'manager.whatsapp_template.submitted')->count())->toBe(0);
    });

    it('erro da Gupshup: 422 com a mensagem da Meta, trilha registra a falha e a trava é liberada', function () {
        wtSaas($this);
        wtGlobal();
        wtStub([], fn () => Http::response(['status' => 'error', 'message' => 'This WhatsApp business account does not have permission to create message template'], 400));

        wtCreate($this)
            ->assertStatus(422)
            ->assertJsonPath('ok', false)
            ->assertJsonPath('error', fn (string $error) => str_contains($error, 'does not have permission to create message template'));

        $audit = DB::table('audit_logs')->where('event', 'manager.whatsapp_template.submitted')->sole();
        expect($audit->new_values)->toContain('"ok":false');

        // A trava foi solta: nova tentativa chega à Gupshup (422 de novo), não fica presa em "busy".
        wtCreate($this)->assertStatus(422);
        expect(wtPosts())->toHaveCount(2);
    });

    it('clique duplo: o mesmo modelo já em envio responde 409 sem chamar a Gupshup', function () {
        wtSaas($this);
        wtGlobal();
        $lock = Cache::lock('whatsapp:template-create:easyeye_teste_conexao|pt_BR', 30);
        expect($lock->get())->toBeTrue();

        wtCreate($this)->assertStatus(409)->assertJson(['ok' => false, 'error' => __('whatsapp.template_api.busy')]);

        Http::assertNothingSent();
    });

    it('valida: chave fora do catálogo e idioma não liberado dão 422 sem chamar a Gupshup', function () {
        wtSaas($this);
        wtGlobal();

        wtCreate($this, 'nao_existe')->assertJsonValidationErrors('key');
        wtCreate($this, 'connection_test', 'en')->assertJsonValidationErrors('language');

        Http::assertNothingSent();
    });
});

describe('página', function () {
    it('informa que a conta é a comum e entrega as rotas da aba Modelos', function () {
        wtSaas($this);
        wtGlobal();

        $props = $this->get(route('manager.whatsapp.index'))->assertOk()->viewData('page')['props'];

        expect($props['templateApi'])->toBeTrue()
            ->and($props['routes']['templates_status'])->toBe(route('manager.whatsapp.templates.status'))
            ->and($props['routes']['templates_create'])->toBe(route('manager.whatsapp.templates.create'));
    });

    it('fora da conta comum (mock) a aba não oferece a criação por API', function () {
        config(['whatsapp.driver' => 'mock']);
        wtSaas($this);
        wtGlobal();

        expect($this->get(route('manager.whatsapp.index'))->assertOk()->viewData('page')['props']['templateApi'])->toBeFalse();
    });
});
