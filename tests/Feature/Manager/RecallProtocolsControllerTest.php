<?php

declare(strict_types=1);

/**
 * Manager → Protocolos de retorno (recall): autorização, as três telas (lista, protocolo,
 * versão) e as ações do rascunho (salvar, congelar, abrir versão, prévia). A regra de
 * negócio em si está em ProtocolCatalogService/ProtocolContentSchema; aqui se prova que a
 * porta HTTP respeita o papel, o escopo do protocolo e devolve erros por campo.
 */

use App\Domains\ClinicalReview\Models\ClinicalGovernanceEvent;
use App\Domains\Recall\Enums\ProtocolVersionState as State;
use App\Domains\Recall\Models\{RecallProtocol, RecallProtocolVersion};
use App\Domains\Recall\Services\ProtocolCatalogService;
use App\Domains\Recall\Support\ProposedProtocolDrafts;
use App\Models\{Entity, User};
use App\Support\{CanonicalJson, PanelNavigation};
use Database\Seeders\RecallProtocolDraftsSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;

beforeEach(function () {
    $this->saas  = Entity::factory()->create(['is_client' => false, 'active' => true]);
    $this->admin = User::factory()->create();
    createEntityUser($this->saas, $this->admin, 'admin');

    $this->seed(RecallProtocolDraftsSeeder::class);
    $this->glaucoma = RecallProtocol::query()->where('key', 'glaucoma')->firstOrFail();
    $this->draft    = $this->glaucoma->versions()->firstOrFail();
});

/** Sessão do admin do SaaS (atravessa entity.selected + saas.admin + saas.role). */
function rpcAsAdmin(?User $user = null): mixed
{
    return test()->actingAs($user ?? test()->admin)->withSession([
        'selected_entity_id'        => test()->saas->id,
        'selected_entity_is_client' => false,
        'selected_entity_user_rule' => 'admin',
    ]);
}

/** @return array<string, mixed> props Inertia da resposta */
function rpcProps(TestResponse $response): array
{
    $props = [];
    $response->assertOk()->assertInertia(function ($page) use (&$props) {
        $props = $page->toArray()['props'];
    });

    return $props;
}

function rpcSetState(RecallProtocolVersion $version, State $state): void
{
    DB::table('recall_protocol_versions')->where('id', $version->id)->update(['state' => $state->value]);
}

/** @return array<string, mixed> */
function rpcContent(): array
{
    return ProposedProtocolDrafts::all()['glaucoma']['content'];
}

// ── autorização ─────────────────────────────────────────────────────────────

it('visitante é mandado ao login em todas as rotas', function () {
    $paramsProtocol = ['protocol' => test()->glaucoma->id];
    $paramsVersion  = $paramsProtocol + ['version' => test()->draft->id];

    foreach ([
        ['get', route('manager.recall-protocols.index')],
        ['get', route('manager.recall-protocols.show', $paramsProtocol)],
        ['get', route('manager.recall-protocols.versions.show', $paramsVersion)],
        ['post', route('manager.recall-protocols.versions.store', $paramsProtocol)],
        ['put', route('manager.recall-protocols.versions.update', $paramsVersion)],
        ['post', route('manager.recall-protocols.versions.freeze', $paramsVersion)],
        ['post', route('manager.recall-protocols.preview', $paramsProtocol)],
    ] as [$method, $url]) {
        test()->{$method}($url)->assertRedirect(route('login'));
    }
});

it('só o admin do SaaS entra: financeiro, suporte, usuário comum e membro de clínica recebem 403', function (string $rule) {
    $user = User::factory()->create();
    createEntityUser($this->saas, $user, $rule);

    $paramsVersion = ['protocol' => $this->glaucoma->id, 'version' => $this->draft->id];

    foreach ([
        ['get', route('manager.recall-protocols.index')],
        ['get', route('manager.recall-protocols.show', ['protocol' => $this->glaucoma->id])],
        ['get', route('manager.recall-protocols.versions.show', $paramsVersion)],
        ['post', route('manager.recall-protocols.versions.store', ['protocol' => $this->glaucoma->id])],
        ['put', route('manager.recall-protocols.versions.update', $paramsVersion)],
        ['post', route('manager.recall-protocols.versions.freeze', $paramsVersion)],
        ['post', route('manager.recall-protocols.preview', ['protocol' => $this->glaucoma->id])],
    ] as [$method, $url]) {
        $this->actingAs($user)
            ->withSession(['selected_entity_id' => $this->saas->id, 'selected_entity_is_client' => false, 'selected_entity_user_rule' => $rule])
            ->{$method . 'Json'}($url, [])
            ->assertForbidden();
    }

    expect($this->draft->fresh()->state)->toBe(State::Draft);
})->with(['financial', 'support', 'user']);

it('usuário de clínica não acessa o Manager', function () {
    $clinic = Entity::factory()->create(['is_client' => true, 'active' => true]);
    $doctor = User::factory()->create();
    createEntityUser($clinic, $doctor, 'doctor');

    $this->actingAs($doctor)
        ->withSession(['selected_entity_id' => $clinic->id, 'selected_entity_is_client' => true, 'selected_entity_user_rule' => 'doctor'])
        ->get(route('manager.recall-protocols.index'))
        ->assertRedirect();

    expect(session('selected_entity_is_client'))->toBeTrue();
});

// ── lista ───────────────────────────────────────────────────────────────────

it('a lista traz os protocolos em ordem, com a versão em andamento e sem versão liberada', function () {
    $props = rpcProps(rpcAsAdmin()->get(route('manager.recall-protocols.index')));

    expect(array_column($props['protocols'], 'key'))->toBe(['glaucoma', 'dmri', 'retinopatia_diabetica', 'ceratocone', 'hidroxicloroquina']);

    $glaucoma = $props['protocols'][0];

    expect($glaucoma['title'])->toBe('Glaucoma')
        ->and($glaucoma['versions_count'])->toBe(1)
        ->and($glaucoma['released'])->toBeNull()
        ->and($glaucoma['open']['version'])->toBe(1)
        ->and($glaucoma['open']['state'])->toBe('draft')
        ->and($glaucoma['open']['short_hash'])->toHaveLength(12)
        ->and($props['t']['title'])->toBe('Protocolos de retorno');
});

it('a lista mostra a versão liberada quando existe', function () {
    rpcSetState($this->draft, State::Released);

    $glaucoma = rpcProps(rpcAsAdmin()->get(route('manager.recall-protocols.index')))['protocols'][0];

    expect($glaucoma['released']['version'])->toBe(1)
        ->and($glaucoma['open'])->toBeNull();
});

// ── protocolo ───────────────────────────────────────────────────────────────

it('a página do protocolo lista as versões da mais nova para a mais antiga, com o histórico', function () {
    rpcSetState($this->draft, State::Declined);
    $v2 = app(ProtocolCatalogService::class)->startRevision($this->glaucoma, $this->admin);

    $props = rpcProps(rpcAsAdmin()->get(route('manager.recall-protocols.show', ['protocol' => $this->glaucoma->id])));

    expect($props['protocol']['key'])->toBe('glaucoma')
        ->and(array_column($props['versions'], 'version'))->toBe([2, 1])
        ->and($props['versions'][0]['id'])->toBe($v2->id)
        ->and($props['versions'][0]['parent_version'])->toBe(1)
        ->and($props['versions'][1]['parent_version'])->toBeNull()
        ->and($props['canStartRevision'])->toBeFalse()
        ->and(array_column($props['events'], 'event'))->toContain('revision_started', 'created')
        ->and($props['events'][0]['version'])->toBe(2)
        ->and($props['events'][0]['label'])->toBe('Nova versão aberta');
});

it('pode abrir nova versão só sem versão em andamento', function () {
    $url = route('manager.recall-protocols.show', ['protocol' => $this->glaucoma->id]);

    expect(rpcProps(rpcAsAdmin()->get($url))['canStartRevision'])->toBeFalse();

    rpcSetState($this->draft, State::Released);

    expect(rpcProps(rpcAsAdmin()->get($url))['canStartRevision'])->toBeTrue();
});

it('protocolo inexistente ou id inválido dá 404', function () {
    rpcAsAdmin()->get(route('manager.recall-protocols.show', ['protocol' => (string) Str::uuid()]))->assertNotFound();
    rpcAsAdmin()->get('/panel/manager/recall-protocols/nao-e-uuid')->assertNotFound();
});

// ── versão ──────────────────────────────────────────────────────────────────

it('a página da versão traz o conteúdo normalizado, o texto canônico, a integridade e as opções do editor', function () {
    $props = rpcProps(rpcAsAdmin()->get(route('manager.recall-protocols.versions.show', ['protocol' => $this->glaucoma->id, 'version' => $this->draft->id])));

    expect($props['version']['editable'])->toBeTrue()
        ->and($props['version']['integrity_ok'])->toBeTrue()
        ->and($props['version']['content_error'])->toBeFalse()
        ->and($props['version']['lock_version'])->toBe(0)
        ->and($props['version']['parent'])->toBeNull()
        ->and($props['content']['tasks'][0]['key'])->toBe('consulta')
        ->and($props['content']['entry']['cid']['strong'])->toContain('H401')
        ->and($props['canonical'])->toBe($this->draft->content)
        ->and($props['diff'])->toBe([])
        ->and($props['examples'])->toHaveCount(3)
        ->and($props['options']['limits']['tasks'])->toBe(8)
        ->and(array_column($props['options']['medications'], 'value'))->toBe(['hydroxychloroquine', 'chloroquine'])
        ->and($props['options']['medications'][0]['label'])->toBe('Hidroxicloroquina')
        ->and($props['ruleT']['labels']['exams']['visual_field'])->toBe('Campo visual (campimetria)')
        ->and($props['ruleT']['entry']['title'])->not->toBeEmpty();
});

it('versão congelada não é editável', function () {
    rpcSetState($this->draft, State::InReview);

    $props = rpcProps(rpcAsAdmin()->get(route('manager.recall-protocols.versions.show', ['protocol' => $this->glaucoma->id, 'version' => $this->draft->id])));

    expect($props['version']['editable'])->toBeFalse();
});

it('os exemplos usam a data pedida (ou hoje, se vier lixo)', function () {
    $url = fn (array $query) => route('manager.recall-protocols.versions.show', ['protocol' => $this->glaucoma->id, 'version' => $this->draft->id] + $query);

    $props = rpcProps(rpcAsAdmin()->get($url(['example_date' => '2026-01-15'])));

    expect($props['exampleDate'])->toBe('2026-01-15')
        ->and($props['examples'][0]['due_at'])->toBe('2026-05-15')
        ->and($props['examples'][0]['invite'])->toBe('2026-04-15');

    foreach (['lixo', '2026-13-45', '15/01/2026', ''] as $bad) {
        expect(rpcProps(rpcAsAdmin()->get($url(['example_date' => $bad])))['exampleDate'])->toBe(now()->toDateString());
    }
});

it('a versão derivada mostra o pai e as diferenças', function () {
    rpcSetState($this->draft, State::Declined);
    $v2 = app(ProtocolCatalogService::class)->startRevision($this->glaucoma);

    $content                                    = $v2->contentArray();
    $content['tasks'][1]['interval']['default'] = 9;
    $content['change_note']                     = 'Campimetria a cada 9 meses.';
    app(ProtocolCatalogService::class)->saveDraft($v2, $content, 0);

    $props = rpcProps(rpcAsAdmin()->get(route('manager.recall-protocols.versions.show', ['protocol' => $this->glaucoma->id, 'version' => $v2->id])));

    expect($props['version']['parent']['version'])->toBe(1)
        ->and($props['version']['parent']['state'])->toBe('declined')
        ->and(collect($props['diff'])->where('section', 'task')->where('field', 'interval.default')->first())
        ->toMatchArray(['key' => 'campimetria', 'from' => 12, 'to' => 9])
        ->and(collect($props['diff'])->where('section', 'change_note')->count())->toBe(1);
});

it('a versão tem que ser do protocolo da URL: id de outro protocolo dá 404 em todas as rotas', function () {
    $dmri = RecallProtocol::query()->where('key', 'dmri')->firstOrFail();
    $url  = ['protocol' => $dmri->id, 'version' => $this->draft->id]; // versão do glaucoma sob o protocolo dmri

    rpcAsAdmin()->get(route('manager.recall-protocols.versions.show', $url))->assertNotFound();
    rpcAsAdmin()->putJson(route('manager.recall-protocols.versions.update', $url), ['content' => rpcContent(), 'lock_version' => 0])->assertNotFound();
    rpcAsAdmin()->postJson(route('manager.recall-protocols.versions.freeze', $url))->assertNotFound();

    expect($this->draft->fresh()->state)->toBe(State::Draft)
        ->and($this->draft->fresh()->lock_version)->toBe(0);
});

it('conteúdo gravado que já não passa no esquema não derruba a tela: aviso e integridade', function () {
    DB::table('recall_protocol_versions')->where('id', $this->draft->id)->update(['content' => '{"estranho":1}']);

    $props = rpcProps(rpcAsAdmin()->get(route('manager.recall-protocols.versions.show', ['protocol' => $this->glaucoma->id, 'version' => $this->draft->id])));

    expect($props['version']['content_error'])->toBeTrue()
        ->and($props['version']['integrity_ok'])->toBeFalse()
        ->and($props['content'])->toBeNull()
        ->and($props['examples'])->toBe([])
        ->and($props['canonical'])->toBe('{"estranho":1}');
});

// ── salvar o rascunho ───────────────────────────────────────────────────────

it('salva o rascunho, avisa e sobe o lock_version', function () {
    $content                                    = rpcContent();
    $content['tasks'][0]['interval']['default'] = 5;

    rpcAsAdmin()
        ->put(route('manager.recall-protocols.versions.update', ['protocol' => $this->glaucoma->id, 'version' => $this->draft->id]), ['content' => $content, 'lock_version' => 0])
        ->assertRedirect()
        ->assertSessionHas('success', 'Rascunho salvo.');

    $saved = $this->draft->fresh();

    expect($saved->lock_version)->toBe(1)
        ->and($saved->contentArray()['tasks'][0]['interval']['default'])->toBe(5)
        ->and($saved->integrityOk())->toBeTrue();
});

it('conteúdo inválido volta com um erro por campo, no caminho do formulário, e não grava', function () {
    $content                                = rpcContent();
    $content['tasks'][0]['interval']['min'] = 99;
    $content['tasks'][1]['key']             = 'Campo Inválido';
    $content['extra']                       = 1;

    rpcAsAdmin()
        ->from('/voltar')
        ->put(route('manager.recall-protocols.versions.update', ['protocol' => $this->glaucoma->id, 'version' => $this->draft->id]), ['content' => $content, 'lock_version' => 0])
        ->assertRedirect('/voltar')
        ->assertSessionHasErrors(['content.tasks.0.interval' => 'O mínimo deve ser menor ou igual ao padrão, e o padrão menor ou igual ao máximo.'])
        ->assertSessionHasErrors(['content.tasks.1.key', 'content.extra']);

    expect($this->draft->fresh()->lock_version)->toBe(0)
        ->and($this->draft->fresh()->content)->toBe($this->draft->content);
});

it('lock_version desatualizado volta como erro do campo, sem sobrescrever', function () {
    $first                                    = rpcContent();
    $first['tasks'][0]['interval']['default'] = 5;
    app(ProtocolCatalogService::class)->saveDraft($this->draft, $first, 0);

    $second                                    = rpcContent();
    $second['tasks'][0]['interval']['default'] = 6;

    rpcAsAdmin()
        ->put(route('manager.recall-protocols.versions.update', ['protocol' => $this->glaucoma->id, 'version' => $this->draft->id]), ['content' => $second, 'lock_version' => 0])
        ->assertSessionHasErrors('lock_version');

    expect($this->draft->fresh()->contentArray()['tasks'][0]['interval']['default'])->toBe(5);
});

it('versão congelada não aceita salvar: aviso de erro e nada muda', function () {
    app(ProtocolCatalogService::class)->freeze($this->draft);
    $before = $this->draft->fresh()->content;

    rpcAsAdmin()
        ->put(route('manager.recall-protocols.versions.update', ['protocol' => $this->glaucoma->id, 'version' => $this->draft->id]), ['content' => rpcContent(), 'lock_version' => 1])
        ->assertSessionHas('error', 'Só o rascunho pode ser editado.');

    expect($this->draft->fresh()->content)->toBe($before);
});

it('o envelope é validado: sem content ou com lock_version inválido', function () {
    $url = route('manager.recall-protocols.versions.update', ['protocol' => $this->glaucoma->id, 'version' => $this->draft->id]);

    rpcAsAdmin()->putJson($url, ['lock_version' => 0])->assertJsonValidationErrors('content');
    rpcAsAdmin()->putJson($url, ['content' => rpcContent()])->assertJsonValidationErrors('lock_version');
    rpcAsAdmin()->putJson($url, ['content' => rpcContent(), 'lock_version' => -1])->assertJsonValidationErrors('lock_version');
    rpcAsAdmin()->putJson($url, ['content' => 'x', 'lock_version' => 0])->assertJsonValidationErrors('content');
});

// ── congelar ────────────────────────────────────────────────────────────────

it('congela o rascunho e registra quem foi', function () {
    rpcAsAdmin()
        ->post(route('manager.recall-protocols.versions.freeze', ['protocol' => $this->glaucoma->id, 'version' => $this->draft->id]))
        ->assertRedirect()
        ->assertSessionHas('success', 'Versão congelada para revisão.');

    $frozen = $this->draft->fresh();
    $event  = ClinicalGovernanceEvent::query()->where('event', 'frozen')->sole();

    expect($frozen->state)->toBe(State::InReview)
        ->and($frozen->frozen_by)->toBe($this->admin->id)
        ->and($event->actor_user_id)->toBe($this->admin->id);
});

it('congelar de novo é recusado com aviso', function () {
    $url = route('manager.recall-protocols.versions.freeze', ['protocol' => $this->glaucoma->id, 'version' => $this->draft->id]);

    rpcAsAdmin()->post($url)->assertSessionHas('success');
    rpcAsAdmin()->post($url)->assertSessionHas('error', 'Só o rascunho pode ser editado.');

    expect(ClinicalGovernanceEvent::query()->where('event', 'frozen')->count())->toBe(1);
});

it('versão derivada sem nota de mudança não congela e o erro vem no campo', function () {
    rpcSetState($this->draft, State::Declined);
    $v2 = app(ProtocolCatalogService::class)->startRevision($this->glaucoma);

    rpcAsAdmin()
        ->post(route('manager.recall-protocols.versions.freeze', ['protocol' => $this->glaucoma->id, 'version' => $v2->id]))
        ->assertSessionHasErrors(['content.change_note' => 'Informe o que mudou em relação à versão anterior.']);

    expect($v2->fresh()->state)->toBe(State::Draft);
});

// ── abrir versão ────────────────────────────────────────────────────────────

it('abre a próxima versão e leva para ela', function () {
    rpcSetState($this->draft, State::Declined);

    $response = rpcAsAdmin()->post(route('manager.recall-protocols.versions.store', ['protocol' => $this->glaucoma->id]));

    $v2 = $this->glaucoma->versions()->where('version', 2)->firstOrFail();

    $response->assertRedirect(route('manager.recall-protocols.versions.show', ['protocol' => $this->glaucoma->id, 'version' => $v2->id]))
        ->assertSessionHas('success', 'Rascunho da versão 2 criado.');

    expect($v2->state)->toBe(State::Draft)
        ->and(ClinicalGovernanceEvent::query()->where('event', 'revision_started')->sole()->actor_user_id)->toBe($this->admin->id);
});

it('não abre versão com outra em andamento', function () {
    rpcAsAdmin()
        ->post(route('manager.recall-protocols.versions.store', ['protocol' => $this->glaucoma->id]))
        ->assertSessionHas('error', 'Já existe uma versão em andamento (rascunho ou em revisão) para este protocolo.');

    expect($this->glaucoma->versions()->count())->toBe(1);
});

// ── prévia ──────────────────────────────────────────────────────────────────

it('a prévia normaliza, hasheia e calcula exemplos sem gravar', function () {
    $content                                    = rpcContent();
    $content['tasks'][0]['interval']['default'] = 5;
    $before                                     = $this->draft->fresh()->content;

    $response = rpcAsAdmin()->postJson(route('manager.recall-protocols.preview', ['protocol' => $this->glaucoma->id]), [
        'content'      => $content,
        'example_date' => '2026-01-15',
    ])->assertOk();

    expect($response->json('valid'))->toBeTrue()
        ->and($response->json('hash'))->toMatch(CanonicalJson::HASH_PATTERN)
        ->and($response->json('examples.0.due_at'))->toBe('2026-06-15')
        ->and($response->json('content.tasks.0.interval.default'))->toBe(5)
        ->and($response->json('bytes'))->toBeGreaterThan(500)
        ->and($this->draft->fresh()->content)->toBe($before);
});

it('a prévia de conteúdo inválido devolve 422 com os erros por caminho', function () {
    $content = rpcContent();
    unset($content['rationale']);

    rpcAsAdmin()->postJson(route('manager.recall-protocols.preview', ['protocol' => $this->glaucoma->id]), ['content' => $content])
        ->assertStatus(422)
        ->assertJson(['valid' => false, 'errors' => ['content.rationale' => 'Campo obrigatório.']]);
});

it('a prévia exige content e data válida', function () {
    $url = route('manager.recall-protocols.preview', ['protocol' => $this->glaucoma->id]);

    rpcAsAdmin()->postJson($url, [])->assertJsonValidationErrors('content');
    rpcAsAdmin()->postJson($url, ['content' => rpcContent(), 'example_date' => '15/01/2026'])->assertJsonValidationErrors('example_date');
});

// ── menu ────────────────────────────────────────────────────────────────────

it('o menu do Manager mostra Protocolos de retorno só para o admin do SaaS', function () {
    $keys = function (string $rule): array {
        session([
            'selected_entity_id'        => $this->saas->id,
            'selected_entity_is_client' => false,
            'selected_entity_user_rule' => $rule,
        ]);

        return array_column(PanelNavigation::build(), 'key');
    };

    expect($keys('admin'))->toContain('recall-protocols')
        ->and($keys('financial'))->not->toContain('recall-protocols')
        ->and($keys('support'))->not->toContain('recall-protocols');
});
