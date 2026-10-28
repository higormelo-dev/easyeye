<?php

declare(strict_types=1);

/**
 * Catálogo de protocolos de retorno: criar, editar rascunho, abrir revisão e congelar —
 * e, junto, as garantias que o banco dá sozinho (trigger de imutabilidade, índices
 * únicos parciais, CHECK, log append-only). Ver ADR 0002.
 */

use App\Domains\ClinicalReview\Models\ClinicalGovernanceEvent;
use App\Domains\Recall\Enums\ProtocolVersionState as State;
use App\Domains\Recall\Exceptions\{InvalidProtocolContentException, ProtocolVersionException};
use App\Domains\Recall\Models\{RecallProtocol, RecallProtocolVersion};
use App\Domains\Recall\Services\ProtocolCatalogService;
use App\Domains\Recall\Support\{ProposedProtocolDrafts, ProtocolContentSchema};
use App\Models\User;
use App\Support\CanonicalJson;
use Database\Seeders\RecallProtocolDraftsSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

function pctService(): ProtocolCatalogService
{
    return app(ProtocolCatalogService::class);
}

function pctProtocol(string $key = 'glaucoma', ?User $actor = null): RecallProtocol
{
    $proposal = ProposedProtocolDrafts::all()[$key];

    return pctService()->createProtocol($key, $proposal['translations'], $proposal['content'], $proposal['sort_order'], $actor);
}

function pctDraft(RecallProtocol $protocol): RecallProtocolVersion
{
    return $protocol->versions()->firstOrFail();
}

/** Muda o estado direto no banco (o que um serviço de governança fará), sem passar pelos ganchos. */
function pctSetState(RecallProtocolVersion $version, State $state): void
{
    DB::table('recall_protocol_versions')->where('id', $version->id)->update(['state' => $state->value]);
}

/** Executa algo que o banco tem que recusar, sem abortar a transação do teste (SAVEPOINT). */
function pctRejected(callable $statement): bool
{
    try {
        DB::transaction($statement);
    } catch (QueryException) {
        return true;
    }

    return false;
}

/** @return array<string, mixed> */
function pctContent(string $key = 'glaucoma'): array
{
    return ProposedProtocolDrafts::all()[$key]['content'];
}

// ── criar ───────────────────────────────────────────────────────────────────

it('cria o protocolo com a versão 1 em rascunho, conteúdo canônico, hash íntegro e evento de governança', function () {
    $actor = User::factory()->create(['name' => 'Ana Admin']);

    $protocol = pctProtocol('glaucoma', $actor);
    $version  = pctDraft($protocol);

    expect($protocol->key)->toBe('glaucoma')
        ->and($protocol->status)->toBe(RecallProtocol::STATUS_ACTIVE)
        ->and($protocol->title('pt_BR'))->toBe('Glaucoma')
        ->and($version->version)->toBe(1)
        ->and($version->state)->toBe(State::Draft)
        ->and($version->parent_version_id)->toBeNull()
        ->and($version->lock_version)->toBe(0)
        ->and($version->schema_version)->toBe(1)
        ->and(CanonicalJson::isCanonical($version->content))->toBeTrue()
        ->and($version->integrityOk())->toBeTrue()
        ->and($version->content_hash)->toMatch(CanonicalJson::HASH_PATTERN)
        ->and($version->contentArray()['entry']['cid']['strong'])->toContain('H401');

    $event = ClinicalGovernanceEvent::query()->where('subject_id', $version->id)->sole();

    expect($event->event)->toBe('created')
        ->and($event->subject_type)->toBe('recall_protocol_version')
        ->and($event->from_state)->toBeNull()
        ->and($event->to_state)->toBe('draft')
        ->and($event->actor_user_id)->toBe($actor->id)
        ->and($event->actor_label)->toBe($actor->name)
        ->and($event->meta)->toBe(['protocol' => 'glaucoma', 'version' => 1]);
});

it('conteúdo inválido não cria nada', function () {
    $content = pctContent();
    unset($content['rationale']);

    expect(fn () => pctService()->createProtocol('glaucoma', [], $content))->toThrow(InvalidProtocolContentException::class)
        ->and(RecallProtocol::query()->count())->toBe(0)
        ->and(RecallProtocolVersion::query()->count())->toBe(0)
        ->and(ClinicalGovernanceEvent::query()->count())->toBe(0);
});

it('a chave do protocolo é única e a falha não deixa versão nem evento órfãos', function () {
    pctProtocol('glaucoma');

    $before = [RecallProtocol::query()->count(), RecallProtocolVersion::query()->count(), ClinicalGovernanceEvent::query()->count()];

    expect(pctRejected(fn () => pctProtocol('glaucoma')))->toBeTrue()
        ->and([RecallProtocol::query()->count(), RecallProtocolVersion::query()->count(), ClinicalGovernanceEvent::query()->count()])->toBe($before);
});

it('o protocolo mostra o texto no idioma pedido e cai para pt_BR', function () {
    $protocol = pctProtocol('glaucoma');

    expect($protocol->title('en'))->toBe('Glaucoma')
        ->and($protocol->text('summary', 'pt_BR'))->toContain('glaucoma')
        ->and($protocol->text('summary', 'es'))->toBe($protocol->text('summary', 'pt_BR'))
        ->and($protocol->text('inexistente'))->toBe('');
});

// ── editar o rascunho ───────────────────────────────────────────────────────

it('salva o rascunho: recalcula texto e hash e sobe o lock_version', function () {
    $version = pctDraft(pctProtocol());
    $oldHash = $version->content_hash;

    $content                                    = pctContent();
    $content['tasks'][0]['interval']['default'] = 5;

    $saved = pctService()->saveDraft($version, $content, 0);

    expect($saved->lock_version)->toBe(1)
        ->and($saved->content_hash)->not->toBe($oldHash)
        ->and($saved->integrityOk())->toBeTrue()
        ->and($saved->contentArray()['tasks'][0]['interval']['default'])->toBe(5)
        ->and($version->fresh()->content)->toBe($saved->content);
});

it('recusa salvar com lock_version desatualizado e não perde o que outra sessão salvou', function () {
    $version = pctDraft(pctProtocol());

    $first                                    = pctContent();
    $first['tasks'][0]['interval']['default'] = 5;
    pctService()->saveDraft($version, $first, 0);

    $second                                    = pctContent();
    $second['tasks'][0]['interval']['default'] = 6;

    try {
        pctService()->saveDraft($version, $second, 0);
        $this->fail('Era para recusar a edição obsoleta.');
    } catch (ProtocolVersionException $e) {
        expect($e->reason())->toBe(ProtocolVersionException::STALE);
    }

    expect($version->fresh()->contentArray()['tasks'][0]['interval']['default'])->toBe(5)
        ->and($version->fresh()->lock_version)->toBe(1);
});

it('conteúdo inválido não altera o rascunho', function () {
    $version = pctDraft(pctProtocol());
    $before  = $version->only(['content', 'content_hash', 'lock_version']);

    $content                                = pctContent();
    $content['tasks'][0]['interval']['min'] = 99;

    expect(fn () => pctService()->saveDraft($version, $content, 0))->toThrow(InvalidProtocolContentException::class)
        ->and($version->fresh()->only(['content', 'content_hash', 'lock_version']))->toBe($before);
});

it('só rascunho aceita edição', function () {
    $version = pctDraft(pctProtocol());
    pctService()->freeze($version);

    try {
        pctService()->saveDraft($version, pctContent(), 1);
        $this->fail('Era para recusar.');
    } catch (ProtocolVersionException $e) {
        expect($e->reason())->toBe(ProtocolVersionException::NOT_DRAFT);
    }
});

// ── congelar ────────────────────────────────────────────────────────────────

it('congela o rascunho para revisão: estado, marcos, texto/hash finais e evento', function () {
    $actor   = User::factory()->create(['name' => 'Beto Gestor']);
    $version = pctDraft(pctProtocol());

    $frozen = pctService()->freeze($version, $actor);

    expect($frozen->state)->toBe(State::InReview)
        ->and($frozen->frozen_at)->not->toBeNull()
        ->and($frozen->frozen_by)->toBe($actor->id)
        ->and($frozen->lock_version)->toBe(1)
        ->and($frozen->integrityOk())->toBeTrue()
        ->and(CanonicalJson::isCanonical($frozen->content))->toBeTrue();

    $event = ClinicalGovernanceEvent::query()->where('subject_id', $version->id)->where('event', 'frozen')->sole();

    expect($event->from_state)->toBe('draft')
        ->and($event->to_state)->toBe('in_review')
        ->and($event->actor_label)->toBe($actor->name)
        ->and($event->meta['content_hash'])->toBe($frozen->content_hash);
});

it('congelar duas vezes é recusado', function () {
    $version = pctDraft(pctProtocol());
    pctService()->freeze($version);

    expect(fn () => pctService()->freeze($version))->toThrow(ProtocolVersionException::class)
        ->and(ClinicalGovernanceEvent::query()->where('event', 'frozen')->count())->toBe(1);
});

// ── abrir revisão ───────────────────────────────────────────────────────────

it('não abre revisão com versão em andamento (rascunho ou em revisão)', function () {
    $protocol = pctProtocol();

    foreach ([State::Draft, State::InReview] as $state) {
        pctSetState(pctDraft($protocol), $state);

        try {
            pctService()->startRevision($protocol);
            $this->fail("Era para recusar com a versão em {$state->value}.");
        } catch (ProtocolVersionException $e) {
            expect($e->reason())->toBe(ProtocolVersionException::OPEN_VERSION_EXISTS);
        }
    }
});

it('abre a próxima versão a partir da última: copia o conteúdo, zera a change_note e aponta o pai', function () {
    $actor    = User::factory()->create();
    $protocol = pctProtocol();
    $v1       = pctDraft($protocol);
    pctSetState($v1, State::Declined);

    $v2 = pctService()->startRevision($protocol, $actor);

    expect($v2->version)->toBe(2)
        ->and($v2->state)->toBe(State::Draft)
        ->and($v2->parent_version_id)->toBe($v1->id)
        ->and($v2->integrityOk())->toBeTrue();

    $content = $v2->contentArray();
    $parent  = $v1->fresh()->contentArray();

    expect($parent['change_note'])->not->toBe('')
        ->and($content['change_note'])->toBe('')
        ->and(array_diff_key($content, ['change_note' => 1]))->toBe(array_diff_key($parent, ['change_note' => 1]));

    $event = ClinicalGovernanceEvent::query()->where('subject_id', $v2->id)->where('event', 'revision_started')->sole();

    expect($event->meta)->toBe(['protocol' => 'glaucoma', 'version' => 2, 'parent_version' => 1]);
});

it('a numeração segue crescendo e parte sempre da última versão', function () {
    $protocol = pctProtocol();
    pctSetState(pctDraft($protocol), State::Retired);

    $v2 = pctService()->startRevision($protocol);
    pctSetState($v2, State::Declined);

    $v3 = pctService()->startRevision($protocol);

    expect($v3->version)->toBe(3)
        ->and($v3->parent_version_id)->toBe($v2->id)
        ->and($protocol->versions()->pluck('version')->all())->toBe([1, 2, 3]);
});

it('versão derivada só congela se disser o que mudou', function () {
    $protocol = pctProtocol();
    pctSetState(pctDraft($protocol), State::Declined);
    $v2 = pctService()->startRevision($protocol);

    try {
        pctService()->freeze($v2);
        $this->fail('Era para recusar sem change_note.');
    } catch (InvalidProtocolContentException $e) {
        expect($e->codes())->toBe(['change_note' => 'change_note_required']);
    }

    expect($v2->fresh()->state)->toBe(State::Draft);

    $content                = $v2->contentArray();
    $content['change_note'] = 'Campimetria passa a 9 meses por sugestão do revisor.';
    $v2                     = pctService()->saveDraft($v2, $content, $v2->lock_version);

    expect(pctService()->freeze($v2)->state)->toBe(State::InReview);
});

// ── imutabilidade: no model ─────────────────────────────────────────────────

it('versão congelada: o model recusa mudar conteúdo, hash, versão, protocolo e pai; e recusa apagar', function () {
    $version = pctService()->freeze(pctDraft(pctProtocol()));

    foreach (['content' => 'x', 'content_hash' => '1:' . str_repeat('a', 64), 'version' => 9, 'schema_version' => 2, 'protocol_id' => (string) Str::uuid(), 'parent_version_id' => (string) Str::uuid()] as $column => $value) {
        $fresh = RecallProtocolVersion::query()->findOrFail($version->id);
        $fresh->forceFill([$column => $value]);

        expect(fn () => $fresh->save())->toThrow(ProtocolVersionException::class);
    }

    expect(fn () => RecallProtocolVersion::query()->findOrFail($version->id)->delete())->toThrow(ProtocolVersionException::class);

    // Marcos e motivos continuam graváveis (é por onde a governança anota suspensão e aposentadoria).
    $fresh = RecallProtocolVersion::query()->findOrFail($version->id);
    $fresh->forceFill(['suspended_reason' => 'Revisão em andamento'])->save();

    expect($fresh->fresh()->suspended_reason)->toBe('Revisão em andamento');
});

it('rascunho pode ser apagado', function () {
    $version = pctDraft(pctProtocol());

    $version->delete();

    expect(RecallProtocolVersion::query()->count())->toBe(0);
});

// ── imutabilidade: no banco (trigger) ───────────────────────────────────────

it('trigger: o banco recusa alterar o que o revisor viu, mesmo por SQL', function () {
    $other   = pctProtocol('dmri');
    $version = pctService()->freeze(pctDraft(pctProtocol()));
    $table   = fn () => DB::table('recall_protocol_versions')->where('id', $version->id);

    $parent = pctDraft($other)->id;

    foreach ([
        'content'           => '{}',
        'content_hash'      => '1:' . str_repeat('b', 64),
        'schema_version'    => 9,
        'version'           => 7,
        'protocol_id'       => $other->id,
        'parent_version_id' => $parent,
    ] as $column => $value) {
        expect(pctRejected(fn () => $table()->update([$column => $value])))->toBeTrue($column);
    }

    // Os marcos e o estado seguem graváveis: é por onde a governança anda.
    expect($table()->update(['suspended_reason' => 'x', 'state' => 'validated']))->toBe(1);
})->skip(fn () => DB::getDriverName() !== 'pgsql', 'trigger só em PostgreSQL');

it('trigger: versão congelada não volta a rascunho nem é apagada', function () {
    $version = pctService()->freeze(pctDraft(pctProtocol()));
    $table   = fn () => DB::table('recall_protocol_versions')->where('id', $version->id);

    expect(pctRejected(fn () => $table()->update(['state' => 'draft'])))->toBeTrue()
        ->and(pctRejected(fn () => $table()->delete()))->toBeTrue()
        ->and($table()->value('state'))->toBe('in_review');
})->skip(fn () => DB::getDriverName() !== 'pgsql', 'trigger só em PostgreSQL');

it('trigger: o rascunho muda e pode ser apagado por SQL (é o único estado livre)', function () {
    $version = pctDraft(pctProtocol());
    $table   = fn () => DB::table('recall_protocol_versions')->where('id', $version->id);

    expect($table()->update(['content' => '{"x":1}']))->toBe(1)
        ->and($table()->delete())->toBe(1);
})->skip(fn () => DB::getDriverName() !== 'pgsql', 'trigger só em PostgreSQL');

// ── índices únicos e CHECK ──────────────────────────────────────────────────

it('no máximo UMA versão liberada por protocolo (índice único parcial); outro protocolo pode ter a sua', function () {
    $protocol = pctProtocol();
    pctSetState(pctDraft($protocol), State::Released);

    $v2 = pctService()->startRevision($protocol);

    expect(pctRejected(fn () => pctSetState($v2, State::Released)))->toBeTrue()
        ->and($v2->fresh()->state)->toBe(State::Draft);

    $otherV1 = pctDraft(pctProtocol('dmri'));
    pctSetState($otherV1, State::Released);

    expect($otherV1->fresh()->state)->toBe(State::Released);
});

it('no máximo UMA versão em andamento por protocolo (índice único parcial)', function () {
    $protocol = pctProtocol();

    $second = fn (State $state) => RecallProtocolVersion::query()->create([
        'protocol_id'    => $protocol->id,
        'version'        => 2,
        'content'        => pctDraft($protocol)->content,
        'content_hash'   => pctDraft($protocol)->content_hash,
        'schema_version' => 1,
        'state'          => $state->value,
    ]);

    expect(pctRejected(fn () => $second(State::Draft)))->toBeTrue()
        ->and(pctRejected(fn () => $second(State::InReview)))->toBeTrue();

    pctSetState(pctDraft($protocol), State::Declined);

    expect($second(State::Draft)->version)->toBe(2);
});

it('a numeração de versão é única por protocolo', function () {
    $protocol = pctProtocol();
    pctSetState(pctDraft($protocol), State::Declined);

    $duplicate = fn () => RecallProtocolVersion::query()->create([
        'protocol_id'    => $protocol->id,
        'version'        => 1,
        'content'        => '{}',
        'content_hash'   => '1:' . str_repeat('c', 64),
        'schema_version' => 1,
        'state'          => State::Draft->value,
    ]);

    expect(pctRejected($duplicate))->toBeTrue();
});

it('CHECK: estado fora da lista, hash malformado e suspended_from inválido são recusados', function () {
    $version = pctDraft(pctProtocol());
    $table   = fn () => DB::table('recall_protocol_versions')->where('id', $version->id);

    expect(pctRejected(fn () => $table()->update(['state' => 'aprovada'])))->toBeTrue('state')
        ->and(pctRejected(fn () => $table()->update(['content_hash' => 'abc'])))->toBeTrue('hash curto')
        ->and(pctRejected(fn () => $table()->update(['content_hash' => '2:' . str_repeat('a', 64)])))->toBeTrue('versão do algoritmo')
        ->and(pctRejected(fn () => $table()->update(['suspended_from_state' => 'draft'])))->toBeTrue('suspended_from_state');
});

it('o protocolo não pode ser apagado enquanto tem versão (restrict)', function () {
    $protocol = pctProtocol();

    expect(pctRejected(fn () => DB::table('recall_protocols')->where('id', $protocol->id)->delete()))->toBeTrue();
});

// ── log de governança append-only ───────────────────────────────────────────

it('o log de governança não aceita UPDATE nem DELETE, nem pelo model nem pelo banco', function () {
    pctProtocol();
    $event = ClinicalGovernanceEvent::query()->firstOrFail();

    expect(fn () => $event->update(['reason' => 'x']))->toThrow(LogicException::class)
        ->and(fn () => $event->delete())->toThrow(LogicException::class);

    if (DB::getDriverName() === 'pgsql') {
        expect(pctRejected(fn () => DB::table('clinical_governance_events')->where('id', $event->id)->update(['reason' => 'x'])))->toBeTrue()
            ->and(pctRejected(fn () => DB::table('clinical_governance_events')->where('id', $event->id)->delete()))->toBeTrue();
    }

    expect(ClinicalGovernanceEvent::query()->whereKey($event->id)->value('reason'))->toBeNull();
});

it('o log guarda ator por id e por nome, sem FK: o registro sobrevive ao ator', function () {
    $actor = User::factory()->create(['name' => 'Carla']);
    pctProtocol('glaucoma', $actor);

    $actorId = $actor->id;
    DB::table('users')->where('id', $actorId)->delete();

    $event = ClinicalGovernanceEvent::query()->firstOrFail();

    expect($event->actor_user_id)->toBe($actorId)
        ->and($event->actor_label)->toBe($actor->name);
});

it('sem ator o evento é do sistema', function () {
    pctProtocol();

    expect(ClinicalGovernanceEvent::query()->firstOrFail()->actor_label)->toBe('system');
});

// ── seeder dos rascunhos ────────────────────────────────────────────────────

it('o seeder cria os 5 protocolos propostos, todos em rascunho e íntegros', function () {
    $this->seed(RecallProtocolDraftsSeeder::class);

    expect(RecallProtocol::query()->orderBy('sort_order')->pluck('key')->all())
        ->toBe(['glaucoma', 'dmri', 'retinopatia_diabetica', 'ceratocone', 'hidroxicloroquina']);

    foreach (RecallProtocolVersion::query()->get() as $version) {
        expect($version->state)->toBe(State::Draft)
            ->and($version->version)->toBe(1)
            ->and($version->integrityOk())->toBeTrue();
    }

    // Invariante I8: nenhuma versão além de `draft` sai de seeder.
    expect(RecallProtocolVersion::query()->where('state', '!=', 'draft')->count())->toBe(0)
        ->and(ClinicalGovernanceEvent::query()->where('event', 'created')->count())->toBe(5);
});

it('o seeder é idempotente e não toca em protocolo que já existe', function () {
    $this->seed(RecallProtocolDraftsSeeder::class);

    $glaucoma = RecallProtocol::query()->where('key', 'glaucoma')->firstOrFail();
    $version  = pctDraft($glaucoma);
    $content  = $version->contentArray();

    $content['tasks'][0]['interval']['default'] = 5;
    $edited                                     = pctService()->saveDraft($version, $content, 0);
    $glaucoma->update(['translations' => ['pt_BR' => ['title' => 'Glaucoma (editado)', 'summary' => '']]]);

    $this->seed(RecallProtocolDraftsSeeder::class);

    expect(RecallProtocol::query()->count())->toBe(5)
        ->and(RecallProtocolVersion::query()->count())->toBe(5)
        ->and(ClinicalGovernanceEvent::query()->count())->toBe(5)
        ->and($glaucoma->fresh()->title('pt_BR'))->toBe('Glaucoma (editado)')
        ->and($edited->fresh()->contentArray()['tasks'][0]['interval']['default'])->toBe(5);
});

it('o seeder completa o catálogo quando falta protocolo, sem recriar os que existem', function () {
    pctProtocol('glaucoma');

    $this->seed(RecallProtocolDraftsSeeder::class);

    expect(RecallProtocol::query()->count())->toBe(5)
        ->and(RecallProtocolVersion::query()->count())->toBe(5);
});

it('integrityOk acusa texto adulterado e hash adulterado (a base da verificação noturna)', function () {
    $version = pctDraft(pctProtocol());

    expect($version->integrityOk())->toBeTrue();

    // O rascunho aceita UPDATE por SQL (é o único estado livre): adultera o texto, depois só o hash.
    DB::table('recall_protocol_versions')->where('id', $version->id)->update(['content' => $version->content . ' ']);
    expect($version->fresh()->integrityOk())->toBeFalse();

    DB::table('recall_protocol_versions')->where('id', $version->id)->update(['content' => $version->content, 'content_hash' => '1:' . str_repeat('0', 64)]);
    expect($version->fresh()->integrityOk())->toBeFalse();

    DB::table('recall_protocol_versions')->where('id', $version->id)->update(['content_hash' => $version->content_hash]);
    expect($version->fresh()->integrityOk())->toBeTrue();
});

it('consultas do protocolo: versão liberada e versão em andamento', function () {
    $protocol = pctProtocol();

    expect($protocol->releasedVersion())->toBeNull()
        ->and($protocol->openVersion()?->id)->toBe(pctDraft($protocol)->id);

    pctSetState(pctDraft($protocol), State::Released);

    expect($protocol->releasedVersion()?->state)->toBe(State::Released)
        ->and($protocol->openVersion())->toBeNull();
});

it('o conteúdo gravado é exatamente o que o esquema normaliza (ida e volta pelo banco)', function () {
    $protocol = pctProtocol();
    $version  = RecallProtocolVersion::query()->findOrFail(pctDraft($protocol)->id);

    expect($version->contentArray())->toEqual(ProtocolContentSchema::normalize(pctContent()))
        ->and($version->content)->toBe(ProtocolContentSchema::canonical(ProtocolContentSchema::normalize(pctContent())));
});
