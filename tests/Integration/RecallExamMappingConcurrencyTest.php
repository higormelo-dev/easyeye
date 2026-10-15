<?php

declare(strict_types=1);

/**
 * Mapeamento de exames do recall × duas sessões PostgreSQL de verdade (achado via revisão adversarial Codex). Salvar troca
 * o CONJUNTO inteiro da clínica; na 1ª configuração não existe linha nenhuma e o `FOR UPDATE` não trava nada, então duas
 * trocas simultâneas partiam de um conjunto vazio: iguais → violação do índice único (erro 500); disjuntas → a UNIÃO
 * gravada, uma configuração que ninguém enviou (e que decide quais exames cumprem os retornos).
 *
 * O processo FILHO salva o mapeamento de verdade e PAUSA depois de ler o conjunto e antes de gravar; o principal salva
 * nessa janela. O 2º salvamento tem que ESPERAR a trava da clínica e partir do conjunto COMITADO: vence o último a
 * salvar, inteiro, e salvamento de OUTRA clínica não espera ninguém.
 *
 * Fica em tests/Integration (DatabaseMigrations, dados comitados) e roda por caminho explícito; depois:
 * php artisan migrate:fresh --env=testing --force.
 */

use App\Domains\Recall\Models\RecallExamMapping;
use App\Domains\Recall\Services\ExamMappingService;
use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\{DB, Notification, Process};
use Illuminate\Support\Str;
use Tests\TestCase;

uses(TestCase::class, DatabaseMigrations::class);

function remChildEnv(array $extra = []): array
{
    $db = config('database.connections.' . config('database.default'));

    return $extra + [
        'APP_ENV'          => 'testing',
        'CACHE_STORE'      => 'array',
        'SESSION_DRIVER'   => 'array',
        'QUEUE_CONNECTION' => 'sync',
        'MAIL_MAILER'      => 'array',
        'DB_CONNECTION'    => config('database.default'),
        'DB_HOST'          => (string) ($db['host'] ?? ''),
        'DB_PORT'          => (string) ($db['port'] ?? ''),
        'DB_DATABASE'      => (string) ($db['database'] ?? ''),
        'DB_USERNAME'      => (string) ($db['username'] ?? ''),
        'DB_PASSWORD'      => (string) ($db['password'] ?? ''),
    ];
}

/**
 * Sobe o filho (que salva `$childMappings` na clínica e pausa no meio) e roda `$mainOperation` no principal nessa janela.
 *
 * @param array<string, list<string>> $childMappings
 *
 * @return array{0: float, 1: ?Throwable, 2: ProcessResult}
 */
function remRace(string $entityId, array $childMappings, callable $mainOperation, float $hold = 2.5): array
{
    $marker = sys_get_temp_dir() . '/rem-' . Str::uuid();

    $child = Process::path(base_path())
        ->env(remChildEnv(['RM_MAPPINGS' => json_encode($childMappings, JSON_THROW_ON_ERROR)]))
        ->timeout(60)
        ->start([PHP_BINARY, 'tests/Integration/Support/recall_mapping_child.php', $entityId, $marker, (string) $hold]);

    try {
        $deadline = microtime(true) + 30;

        while (! is_file($marker) && microtime(true) < $deadline && $child->running()) {
            usleep(50_000);
        }

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

/** @return list<string> "chave:tipo" das linhas gravadas da clínica, em ordem estável */
function remRows(string $entityId): array
{
    return RecallExamMapping::query()->withoutGlobalScopes()->where('entity_id', $entityId)->get()
        ->map(static fn (RecallExamMapping $row): string => $row->exam_key . ':' . $row->exam_type_id)
        ->sort()
        ->values()
        ->all();
}

beforeEach(function () {
    Notification::fake();

    ['entity' => $this->entity] = rcfClinic(recall: true);

    $this->campimetria = rcfExamType('Campimetria computadorizada');
    $this->macula      = rcfExamType('OCT de mácula');
});

it('[CORRIDA] dois salvamentos IGUAIS ao mesmo tempo: o 2º espera a trava, enxerga o conjunto comitado e não bate no índice único', function () {
    $same = ['visual_field' => [(string) $this->campimetria->id]];

    [$waited, $thrown, $child] = remRace(
        (string) $this->entity->id,
        $same,
        fn () => app(ExamMappingService::class)->sync((string) $this->entity->id, $same),
    );

    expect($child->successful())->toBeTrue("filho falhou — stderr: {$child->errorOutput()}")
        ->and($thrown)->toBeNull()
        // Esperou o filho comitar (ele segura a transação 2,5 s)...
        ->and($waited)->toBeGreaterThan(1.0)
        // ...e o conjunto final é o enviado, uma linha só.
        ->and(remRows((string) $this->entity->id))->toBe(['visual_field:' . $this->campimetria->id]);
});

it('[CORRIDA] dois salvamentos DISJUNTOS: vence o último, inteiro — a união dos dois nunca fica gravada', function () {
    [$waited, $thrown, $child] = remRace(
        (string) $this->entity->id,
        ['visual_field' => [(string) $this->campimetria->id]],
        fn ()           => app(ExamMappingService::class)->sync((string) $this->entity->id, ['oct_macula' => [(string) $this->macula->id]]),
    );

    expect($child->successful())->toBeTrue("filho falhou — stderr: {$child->errorOutput()}")
        ->and($thrown)->toBeNull()
        ->and($waited)->toBeGreaterThan(1.0)
        // O principal salvou depois do filho: o conjunto dele substitui o do filho por inteiro.
        ->and(remRows((string) $this->entity->id))->toBe(['oct_macula:' . $this->macula->id]);
});

it('[CORRIDA] salvar o mapeamento de OUTRA clínica não espera: a trava é por clínica', function () {
    ['entity' => $other] = rcfClinic(recall: true);

    [$waited, $thrown, $child] = remRace(
        (string) $this->entity->id,
        ['visual_field' => [(string) $this->campimetria->id]],
        fn ()           => app(ExamMappingService::class)->sync((string) $other->id, ['visual_field' => [(string) $this->campimetria->id]]),
    );

    expect($child->successful())->toBeTrue("filho falhou — stderr: {$child->errorOutput()}")
        ->and($thrown)->toBeNull()
        ->and($waited)->toBeLessThan(1.5)
        ->and(remRows((string) $this->entity->id))->toBe(['visual_field:' . $this->campimetria->id])
        ->and(remRows((string) $other->id))->toBe(['visual_field:' . $this->campimetria->id]);
});
