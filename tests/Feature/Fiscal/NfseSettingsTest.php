<?php

use App\Models\{AuditLog, Entity};
use App\Models\Fiscal\NfseCredential;
use App\Support\Fiscal\NfseSettings;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\{DB, Http};

require_once __DIR__ . '/Support/NfseFixtures.php';

beforeEach(function () {
    Http::preventStrayRequests();
    $this->saas = Entity::factory()->create(['is_client' => false, 'active' => true]);
});

describe('página', function () {
    it('admin e financeiro veem a página; o resto não', function () {
        foreach (['admin', 'financial'] as $rule) {
            nfseManager($rule)->get(route('manager.nfse.index'))->assertOk();
        }

        nfseManager('support')->get(route('manager.nfse.index'))->assertForbidden();
        $this->post(route('logout'));
    });

    it('entrega as props do contrato: settings aninhado, credenciais SEM token, URLs do gatilho, permissões e textos', function () {
        nfseReady();
        nfseDocument(nfsePaidInvoice());
        nfsePaidInvoice(); // paga sem nota
        nfseClinic(['ibge_code' => null]);

        $props = nfseManager('admin')->get(route('manager.nfse.index'))->assertOk()->viewData('page')['props'];

        expect(array_keys($props['settings']))->toEqualCanonicalizing(['prestador', 'standard', 'environment', 'auto_issue', 'copy_email', 'discriminacao'])
            ->and($props['settings']['prestador']['cnpj'])->toBe(NFSE_TEST_CNPJ)
            ->and($props['credentials'])->toHaveCount(2)
            ->and($props['credentials'][0])->toHaveKeys(['environment', 'configured', 'has_token', 'has_master_token', 'hook', 'last_test'])
            ->and($props['credentials'][0]['has_token'])->toBeTrue()
            ->and($props['hook_urls']['homologacao'])->toContain('/api/fiscal/focusnfe/webhook/')
            ->and($props['hook_urls']['producao'])->toBeNull()
            ->and($props['documents'])->toHaveCount(1)
            ->and($props['pending_invoices'])->toHaveCount(1)
            ->and($props['pending_invoices'][0])->toHaveKeys(['id', 'reference', 'entity', 'amount', 'currency', 'paid_at', 'billing_reason', 'issue_url'])
            ->and(count($props['entities_missing_ibge']))->toBeGreaterThanOrEqual(1)
            ->and($props['permissions'])->toBe(['can_settings' => true, 'can_issue' => true, 'can_cancel' => true])
            ->and($props['t'])->toBeArray()->not->toBeEmpty();

        // Nenhum segredo em lugar nenhum da resposta.
        expect(json_encode(Arr::except($props, ['t', 'ziggy'])))->not->toContain('SEGREDO')->not->toContain('"token"')->not->toContain('webhook_secret');
    });

    it('financeiro vê a página mas sem permissão de configurar nem cancelar', function () {
        nfseReady();

        $props = nfseManager('financial')->get(route('manager.nfse.index'))->viewData('page')['props'];

        expect($props['permissions'])->toBe(['can_settings' => false, 'can_issue' => true, 'can_cancel' => false]);
    });
});

describe('configuração', function () {
    it('o admin grava o formato aninhado; o financeiro não pode', function () {
        nfseManager('financial')->putJson(route('manager.nfse.settings.update'), ['auto_issue' => false])->assertForbidden();

        nfseManager('admin')->putJson(route('manager.nfse.settings.update'), [
            'prestador'     => ['cnpj' => '11.222.333/0001-81', 'inscricao_municipal' => '12345', 'codigo_municipio' => '3550308', 'item_lista_servico' => '01.07', 'aliquota_iss' => 0.05, 'optante_simples_nacional' => true],
            'standard'      => 'nacional', 'environment' => 'producao', 'copy_email' => 'contador@dono.test',
            'discriminacao' => ['plano' => 'Assinatura {plano}'],
        ])->assertOk()->assertJsonPath('settings.prestador.cnpj', NFSE_TEST_CNPJ)->assertJsonPath('settings.standard', 'nacional');

        $settings = new NfseSettings();

        expect($settings->prestador())->toMatchArray(['cnpj' => NFSE_TEST_CNPJ, 'codigo_municipio' => '3550308', 'aliquota_iss' => 0.05, 'optante_simples_nacional' => true])
            ->and($settings->standard()->value)->toBe('nacional')
            ->and($settings->copyEmail())->toBe('contador@dono.test');
    });

    it('valida os campos', function (array $payload, string $field) {
        nfseManager('admin')->putJson(route('manager.nfse.settings.update'), $payload)->assertStatus(422)->assertJsonValidationErrors([$field]);
    })->with([
        'cnpj inválido'       => [['prestador' => ['cnpj' => '11222333000182']], 'prestador.cnpj'],
        'ibge curto'          => [['prestador' => ['codigo_municipio' => '355030']], 'prestador.codigo_municipio'],
        'alíquota em %'       => [['prestador' => ['aliquota_iss' => 5]], 'prestador.aliquota_iss'],
        'natureza fora'       => [['prestador' => ['natureza_operacao' => '9']], 'prestador.natureza_operacao'],
        'série fora'          => [['prestador' => ['serie_dps' => 50000]], 'prestador.serie_dps'],
        'padrão desconhecido' => [['standard' => 'federal'], 'standard'],
        'ambiente'            => [['environment' => 'staging'], 'environment'],
        'e-mail'              => [['copy_email' => 'nao-e-email'], 'copy_email'],
        'código nacional'     => [['prestador' => ['codigo_tributacao_nacional_iss' => '0107']], 'prestador.codigo_tributacao_nacional_iss'],
    ]);

    it('o contador da DPS não volta para número já usado na série', function () {
        nfseReady();
        nfseDocument(nfsePaidInvoice(), ['dps_series' => 1, 'dps_number' => 50, 'standard' => 'nacional']);

        nfseManager('admin')->putJson(route('manager.nfse.settings.update'), ['prestador' => ['proximo_numero_dps' => 50]])->assertStatus(422)->assertJsonValidationErrors(['prestador.proximo_numero_dps']);
        nfseManager('admin')->putJson(route('manager.nfse.settings.update'), ['prestador' => ['proximo_numero_dps' => 51]])->assertOk();
    });

    it('só liga a emissão automática com prestador completo e token do ambiente', function () {
        nfseManager('admin')->putJson(route('manager.nfse.settings.update'), ['auto_issue' => true])->assertStatus(422)->assertJsonValidationErrors(['auto_issue']);

        nfseReady(); // prestador + token de homologação

        nfseManager('admin')->putJson(route('manager.nfse.settings.update'), ['auto_issue' => true])->assertOk();
        nfseManager('admin')->putJson(route('manager.nfse.settings.update'), ['environment' => 'producao', 'auto_issue' => true])->assertStatus(422);

        expect((new NfseSettings())->autoIssue())->toBeTrue();
    });

    it('grava na auditoria o que mudou, sem nenhum segredo', function () {
        nfseManager('admin')->putJson(route('manager.nfse.settings.update'), ['prestador' => ['cnpj' => NFSE_TEST_CNPJ]])->assertOk();

        $audit = AuditLog::query()->where('event', 'manager.nfse.settings')->latest('created_at')->first();

        expect($audit)->not->toBeNull()->and(json_encode($audit->new_values))->toContain(NFSE_TEST_CNPJ);
    });
});

describe('credenciais', function () {
    it('grava os tokens cifrados, gera token e segredo do gatilho UMA vez e nunca devolve segredo', function () {
        $response = nfseManager('admin')->postJson(route('manager.nfse.credentials.store', 'homologacao'), [
            'token' => 'tok-da-empresa-12345', 'master_token' => 'tok-master-12345', 'reason' => 'Primeira configuração da Focus NFe em homologação.',
        ])->assertOk()->assertJsonPath('credential.has_token', true)->assertJsonPath('credential.has_master_token', true);

        expect($response->getContent())->not->toContain('tok-da-empresa')->not->toContain('tok-master');

        $credential = NfseCredential::query()->sole();
        $raw        = DB::table('nfse_credentials')->first();

        expect($credential->token)->toBe('tok-da-empresa-12345')
            ->and($raw->token)->not->toContain('tok-da-empresa')
            ->and(strlen($credential->webhook_token))->toBe(48)
            ->and(strlen($credential->webhook_secret))->toBe(64);

        $hook = $credential->webhook_token;

        // Trocar só o master: o token da empresa e o gatilho permanecem.
        nfseManager('admin')->postJson(route('manager.nfse.credentials.store', 'homologacao'), ['master_token' => 'novo-master-12345', 'reason' => 'Troca do token master por rotação.'])->assertOk();

        $fresh = NfseCredential::query()->sole();

        expect($fresh->token)->toBe('tok-da-empresa-12345')->and($fresh->master_token)->toBe('novo-master-12345')->and($fresh->webhook_token)->toBe($hook);
    });

    it('exige motivo de 20+, algum token e ambiente válido; só admin', function () {
        $url = route('manager.nfse.credentials.store', 'homologacao');

        nfseManager('admin')->postJson($url, ['token' => 'tok-12345678', 'reason' => 'curto'])->assertStatus(422)->assertJsonValidationErrors(['reason']);
        nfseManager('admin')->postJson($url, ['reason' => 'Motivo com mais de vinte caracteres.'])->assertStatus(422)->assertJsonValidationErrors(['token']);
        nfseManager('financial')->postJson($url, ['token' => 'tok-12345678', 'reason' => 'Motivo com mais de vinte caracteres.'])->assertForbidden();
        nfseManager('admin')->postJson('/panel/manager/nfse/credentials/staging', [])->assertNotFound();

        expect(NfseCredential::query()->count())->toBe(0);
    });

    it('a auditoria registra só as flags, nunca o token', function () {
        nfseManager('admin')->postJson(route('manager.nfse.credentials.store', 'producao'), ['token' => 'tok-prod-SEGREDO-1', 'reason' => 'Token de produção entregue pela Focus.'])->assertOk();

        $dump = AuditLog::query()->get()->map(fn ($a) => json_encode([$a->old_values, $a->new_values, $a->reason]))->implode(' ');

        expect($dump)->not->toContain('SEGREDO')->and($dump)->toContain('has_token');
    });

    it('testar credencial: chamada barata (lista de gatilhos), grava o resultado e responde 422 quando falha', function () {
        nfseReady();
        Http::fake([
            '*/v2/hooks'        => Http::sequence()->push([], 200)->push('HTTP Basic: Access denied', 401),
            '*/v2/municipios/*' => Http::response(['codigo_municipio' => '3550308', 'nfse_habilitada' => true], 200),
        ]);

        nfseManager('admin')->postJson(route('manager.nfse.credentials.test', 'homologacao'))->assertOk()->assertJsonPath('ok', true);

        expect(NfseCredential::query()->sole()->last_test_ok)->toBeTrue();

        nfseManager('admin')->postJson(route('manager.nfse.credentials.test', 'homologacao'))->assertStatus(422)->assertJsonPath('ok', false);

        $credential = NfseCredential::query()->sole();

        expect($credential->last_test_ok)->toBeFalse()->and($credential->last_test_message)->toBe(__('fiscal.errors.focus_auth'));
    });
});

/** Requisição vinda do endereço público (https) do app, como em produção atrás do proxy. */
function nfsePublicManager(): mixed
{
    config(['fiscal.webhook.public_url' => 'https://app.easyeye.com.br']);

    return nfseManager('admin');
}

describe('gatilho (webhook)', function () {
    beforeEach(function () {
        nfseReady();
    });

    it('registra na Focus com o segredo e o cabeçalho e guarda o id', function () {
        Http::fake(['*/v2/hooks' => Http::response(['id' => 'Vj5rmkBq', 'event' => 'nfse'], 200)]);

        nfsePublicManager()->postJson(route('manager.nfse.hooks.store'), ['environment' => 'homologacao', 'reason' => 'Registro do gatilho de homologação.'])
            ->assertOk()->assertJsonPath('credential.hook.registered', true)->assertJsonPath('credential.hook.hook_id', 'Vj5rmkBq');

        $credential = NfseCredential::query()->sole();

        Http::assertSent(fn (Request $r) => $r->method() === 'POST' && $r->data() === [
            'cnpj'          => NFSE_TEST_CNPJ, 'event' => 'nfse', 'url' => 'https://app.easyeye.com.br/api/fiscal/focusnfe/webhook/' . $credential->webhook_token,
            'authorization' => $credential->webhook_secret, 'authorization_header' => 'X-FocusNfe-Secret',
        ]);

        expect($credential->hook_id)->toBe('Vj5rmkBq')->and($credential->hook_event)->toBe('nfse');
    });

    it('registrar de novo remove o gatilho antigo primeiro (idempotente)', function () {
        NfseCredential::query()->update(['hook_id' => 'antigo', 'hook_event' => 'nfse']);
        Http::fake(['*/v2/hooks/antigo' => Http::response(['deleted' => true], 200), '*/v2/hooks' => Http::response(['id' => 'novo'], 200)]);

        nfsePublicManager()->postJson(route('manager.nfse.hooks.store'), ['environment' => 'homologacao', 'reason' => 'Re-registro do gatilho após mudar o padrão.'])->assertOk();

        Http::assertSent(fn (Request $r) => $r->method() === 'DELETE' && str_ends_with($r->url(), '/v2/hooks/antigo'));

        expect(NfseCredential::query()->sole()->hook_id)->toBe('novo');
    });

    it('remove o gatilho na Focus e limpa o registro', function () {
        NfseCredential::query()->update(['hook_id' => 'abc', 'hook_event' => 'nfse']);
        Http::fake(['*/v2/hooks/abc' => Http::response(['deleted' => true], 200)]);

        nfseManager('admin')->deleteJson(route('manager.nfse.hooks.destroy', 'homologacao'), ['reason' => 'Remoção do gatilho para trocar a URL.'])->assertOk()->assertJsonPath('credential.hook.registered', false);

        expect(NfseCredential::query()->sole()->hook_id)->toBeNull();
    });

    it('recusa URL que a Focus não alcança, token ausente, motivo curto e quem não é admin', function () {
        nfseManager('admin')->postJson(route('manager.nfse.hooks.store'), ['environment' => 'homologacao', 'reason' => 'Registro do gatilho de homologação.'])->assertStatus(422); // http://localhost

        nfsePublicManager()->postJson(route('manager.nfse.hooks.store'), ['environment' => 'homologacao', 'reason' => 'curto'])->assertStatus(422);
        nfseManager('financial')->postJson(route('manager.nfse.hooks.store'), ['environment' => 'homologacao', 'reason' => 'Registro do gatilho de homologação.'])->assertForbidden();

        NfseCredential::query()->update(['token' => null]);
        nfsePublicManager()->postJson(route('manager.nfse.hooks.store'), ['environment' => 'homologacao', 'reason' => 'Registro do gatilho de homologação.'])->assertStatus(422);

        Http::assertNothingSent();
    });
});

describe('município e IBGE das clínicas', function () {
    beforeEach(fn () => nfseReady());

    it('devolve as obrigatoriedades do município do prestador (e refaz a consulta com refresh)', function () {
        Http::fake(['*/v2/municipios/3550308' => Http::response(['codigo_municipio' => '3550308', 'nome_municipio' => 'São Paulo', 'sigla_uf' => 'SP', 'nfse_habilitada' => true, 'possui_cancelamento_nfse' => true, 'endereco_obrigatorio_nfse' => true], 200)]);

        nfseManager('financial')->getJson(route('manager.nfse.municipality'))->assertOk()
            ->assertJsonPath('municipality.nome_municipio', 'São Paulo')->assertJsonPath('municipality.endereco_obrigatorio_nfse', true)->assertJsonPath('municipality.stale', false);

        nfseManager('financial')->getJson(route('manager.nfse.municipality', ['refresh' => 1]))->assertOk();

        Http::assertSentCount(2);
    });

    it('valida o IBGE e traduz o erro da Focus', function () {
        nfseManager('admin')->getJson(route('manager.nfse.municipality', ['ibge' => '123']))->assertStatus(422);

        Http::fake(['*' => Http::response('HTTP Basic: Access denied', 401)]);

        nfseManager('admin')->getJson(route('manager.nfse.municipality', ['ibge' => '3550308']))->assertStatus(422)->assertJsonPath('message', __('fiscal.errors.focus_auth'));
    });

    it('busca município por nome/UF; sem token master avisa em vez de falhar calado', function () {
        Http::fake(['*/v2/municipios*' => Http::response([['codigo_municipio' => '3550308', 'nome_municipio' => 'São Paulo', 'sigla_uf' => 'SP', 'nfse_habilitada' => true]], 200)]);

        nfseManager('admin')->getJson(route('manager.nfse.municipalities.search', ['q' => 'sao pau', 'uf' => 'SP']))->assertOk()->assertJsonPath('data.0.codigo_municipio', '3550308');
        nfseManager('admin')->getJson(route('manager.nfse.municipalities.search', ['q' => 's']))->assertStatus(422);

        NfseCredential::query()->update(['master_token' => null]);

        nfseManager('admin')->getJson(route('manager.nfse.municipalities.search', ['q' => 'sao pau']))->assertStatus(422)->assertJsonPath('message', __('fiscal.errors.credencial_ausente'));
    });

    it('grava o IBGE da clínica só depois de a Focus confirmar, e audita', function () {
        $clinic = nfseClinic(['ibge_code' => null, 'state' => 'SP']);

        Http::fake(['*/v2/municipios/3550308' => Http::response(['codigo_municipio' => '3550308', 'nome_municipio' => 'São Paulo', 'sigla_uf' => 'SP'], 200)]);

        nfseManager('admin')->putJson(route('manager.nfse.entities.ibge-code', $clinic), ['ibge_code' => '3550308'])->assertOk()
            ->assertJsonPath('entity.ibge_code', '3550308')->assertJsonPath('municipality.matches_state', true);

        expect($clinic->fresh()->ibge_code)->toBe('3550308');
        expect(AuditLog::query()->where('event', 'manager.nfse.entities.ibge-code')->count())->toBe(1);
    });

    it('IBGE que a Focus não conhece (404), formato errado, clínica do SaaS ou perfil sem permissão: nada é gravado', function () {
        $clinic = nfseClinic(['ibge_code' => null]);

        Http::fake(['*' => Http::response(['codigo' => 'nao_encontrado', 'mensagem' => 'não existe'], 404)]);

        nfseManager('admin')->putJson(route('manager.nfse.entities.ibge-code', $clinic), ['ibge_code' => '9999999'])->assertStatus(422);
        nfseManager('admin')->putJson(route('manager.nfse.entities.ibge-code', $clinic), ['ibge_code' => '123'])->assertStatus(422);
        nfseManager('admin')->putJson(route('manager.nfse.entities.ibge-code', $this->saas), ['ibge_code' => '3550308'])->assertNotFound();
        nfseManager('financial')->putJson(route('manager.nfse.entities.ibge-code', $clinic), ['ibge_code' => '3550308'])->assertForbidden();

        expect($clinic->fresh()->ibge_code)->toBeNull();
    });
});
