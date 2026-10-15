<?php

declare(strict_types=1);

/**
 * Catálogo de protocolos de retorno × duas sessões PostgreSQL de verdade (ADR 0002).
 *
 * Em cada cenário o processo FILHO faz a operação e SEGURA a transação aberta alguns
 * segundos (com a linha travada); o principal tenta a mesma coisa nessa janela. O
 * serviço tem que ESPERAR a trava (FOR UPDATE) e, depois do commit do outro, decidir
 * pelo estado COMITADO — com erro de negócio claro, nunca sobrescrevendo conteúdo
 * congelado nem abrindo duas revisões.
 *
 * Fica em tests/Integration (DatabaseMigrations, dados comitados) e roda por caminho
 * explícito; depois: php artisan migrate:fresh --env=testing --force.
 */

use App\Domains\ClinicalReview\Models\ClinicalGovernanceEvent;
use App\Domains\ClinicalReview\Services\GovernanceLog;
use App\Domains\Recall\Enums\ProtocolVersionState as State;
use App\Domains\Recall\Exceptions\ProtocolVersionException;
use App\Domains\Recall\Models\{RecallProtocol, RecallProtocolVersion};
use App\Domains\Recall\Services\ProtocolCatalogService;
use App\Domains\Recall\Support\ProposedProtocolDrafts;
use App\Models\User;
use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\{DB, Process};
use Illuminate\Support\Str;
use Tests\TestCase;

uses(TestCase::class, DatabaseMigrations::class);

/** Ambiente do processo filho: mesmo banco de teste desta suíte. */
function rccChildEnv(): array
{
    $db = config('database.connections.' . config('database.default'));

    return [
        'APP_ENV'          => 'testing',
        'CACHE_STORE'      => 'array',
        'SESSION_DRIVER'   => 'array',
        'QUEUE_CONNECTION' => 'sync',
        'DB_CONNECTION'    => config('database.default'),
        'DB_HOST'          => (string) ($db['host'] ?? ''),
        'DB_PORT'          => (string) ($db['port'] ?? ''),
        'DB_DATABASE'      => (string) ($db['database'] ?? ''),
        'DB_USERNAME'      => (string) ($db['username'] ?? ''),
        'DB_PASSWORD'      => (string) ($db['password'] ?? ''),
    ];
}

function rccProtocol(): RecallProtocol
{
    $proposal = ProposedProtocolDrafts::all()['glaucoma'];

    return app(ProtocolCatalogService::class)->createProtocol('glaucoma', $proposal['translations'], $proposal['content']);
}

/**
 * Sobe o filho, espera ele estar com a operação feita e a transação aberta (marcador) e
 * roda `$mainOperation` no principal nessa janela. Devolve quanto o principal esperou, o
 * que ele lançou (ou null) e o resultado do filho.
 *
 * @return array{0: float, 1: ?Throwable, 2: ProcessResult}
 */
function rccRaceAgainstChild(string $mode, string $id, callable $mainOperation, float $hold = 2.5): array
{
    $marker = sys_get_temp_dir() . '/rcc-' . Str::uuid();

    $child = Process::path(base_path())
        ->env(rccChildEnv())
        ->timeout(60)
        ->start([PHP_BINARY, 'tests/Integration/Support/recall_catalog_child.php', $mode, $id, $marker, (string) $hold]);

    try {
        $deadline = microtime(true) + 30;

        while (! is_file($marker) && microtime(true) < $deadline && $child->running()) {
            usleep(50_000);
        }

        // Rede de segurança contra pendurar a suíte.
        DB::statement("set statement_timeout = '30s'");

        $startedAt = microtime(true);
        $thrown    = null;

        try {
            $mainOperation();
        } catch (Throwable $e) {
            $thrown = $e;
        }

        return [microtime(true) - $startedAt, $thrown, $child->wait()];
    } finally {
        @unlink($marker);
    }
}

it('[CORRIDA] duas aberturas de revisão do mesmo protocolo: a 2ª espera a trava e é recusada, só nasce UMA versão 2', function () {
    $protocol = rccProtocol();
    $v1       = $protocol->versions()->firstOrFail();
    DB::table('recall_protocol_versions')->where('id', $v1->id)->update(['state' => 'declined']);

    [$waited, $thrown, $child] = rccRaceAgainstChild(
        'start-revision',
        (string) $protocol->id,
        fn () => app(ProtocolCatalogService::class)->startRevision($protocol),
    );

    expect($child->successful())->toBeTrue("filho falhou — stderr: {$child->errorOutput()}")
        // Esperou o filho comitar a v2 (ele a segura 2,5 s)...
        ->and($waited)->toBeGreaterThan(1.0)
        // ...e viu a versão em andamento COMITADA: erro de negócio, não violação de índice.
        ->and($thrown)->toBeInstanceOf(ProtocolVersionException::class)
        ->and($thrown->reason())->toBe(ProtocolVersionException::OPEN_VERSION_EXISTS)
        ->and(RecallProtocolVersion::query()->where('protocol_id', $protocol->id)->pluck('version')->all())->toEqualCanonicalizing([1, 2])
        ->and(ClinicalGovernanceEvent::query()->where('event', 'revision_started')->count())->toBe(1);
});

it('[CORRIDA] congelar × salvar o rascunho: o salvamento espera, vê a versão congelada e é recusado — o conteúdo congelado não é sobrescrito', function () {
    $protocol = rccProtocol();
    $version  = $protocol->versions()->firstOrFail();

    $content                                    = $version->contentArray();
    $content['tasks'][0]['interval']['default'] = 5;

    [$waited, $thrown, $child] = rccRaceAgainstChild(
        'freeze',
        (string) $version->id,
        fn () => app(ProtocolCatalogService::class)->saveDraft($version, $content, 0),
    );

    $frozen = RecallProtocolVersion::query()->findOrFail($version->id);

    expect($child->successful())->toBeTrue("filho falhou — stderr: {$child->errorOutput()}")
        ->and($waited)->toBeGreaterThan(1.0)
        ->and($thrown)->toBeInstanceOf(ProtocolVersionException::class)
        ->and($thrown->reason())->toBe(ProtocolVersionException::NOT_DRAFT)
        ->and($frozen->state)->toBe(State::InReview)
        // O que está congelado é o que o filho congelou (padrão 4), não o salvamento que perdeu a corrida.
        ->and($frozen->contentArray()['tasks'][0]['interval']['default'])->toBe(4)
        ->and($frozen->integrityOk())->toBeTrue();
});

it('[CORRIDA] dois congelamentos: o 2º espera, vê em revisão e é recusado; um só evento de congelamento', function () {
    $version = rccProtocol()->versions()->firstOrFail();

    [$waited, $thrown, $child] = rccRaceAgainstChild(
        'freeze',
        (string) $version->id,
        fn () => app(ProtocolCatalogService::class)->freeze($version),
    );

    expect($child->successful())->toBeTrue("filho falhou — stderr: {$child->errorOutput()}")
        ->and($waited)->toBeGreaterThan(1.0)
        ->and($thrown)->toBeInstanceOf(ProtocolVersionException::class)
        ->and($thrown->reason())->toBe(ProtocolVersionException::NOT_DRAFT)
        ->and(ClinicalGovernanceEvent::query()->where('event', 'frozen')->count())->toBe(1);
});

it('o log de governança recusa gravar fora de uma transação (a prova vai junto com a decisão ou não vai)', function () {
    expect(DB::transactionLevel())->toBe(0);

    $version = rccProtocol()->versions()->firstOrFail();

    expect(fn () => app(GovernanceLog::class)->record(GovernanceLog::SUBJECT_PROTOCOL_VERSION, (string) $version->id, 'teste'))
        ->toThrow(LogicException::class)
        ->and(ClinicalGovernanceEvent::query()->where('event', 'teste')->count())->toBe(0);
});

it('se o evento de governança falha, a decisão não vale: o congelamento é desfeito inteiro', function () {
    $version = rccProtocol()->versions()->firstOrFail();

    // Um log que explode no meio do congelamento (banco indisponível, coluna estourada...).
    app()->bind(GovernanceLog::class, fn () => new class() extends GovernanceLog {
        public function record(string $subjectType, string $subjectId, string $event, ?string $fromState = null, ?string $toState = null, ?User $actor = null, ?string $reason = null, array $meta = [], ?string $actorLabel = null): ClinicalGovernanceEvent
        {
            throw new RuntimeException('log indisponível');
        }
    });

    expect(fn () => app(ProtocolCatalogService::class)->freeze($version))->toThrow(RuntimeException::class);

    $reread = RecallProtocolVersion::query()->findOrFail($version->id);

    expect($reread->state)->toBe(State::Draft)
        ->and($reread->frozen_at)->toBeNull();
});
