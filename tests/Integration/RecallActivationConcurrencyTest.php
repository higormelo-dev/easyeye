<?php

declare(strict_types=1);

/**
 * Ativação de protocolo pela clínica × duas sessões PostgreSQL de verdade (ADR 0002, itens 3 e 8).
 *
 * Em cada cenário o processo FILHO faz a operação e SEGURA a transação aberta alguns segundos (com a linha do
 * protocolo travada); o principal tenta a operação concorrente nessa janela. O serviço tem que ESPERAR a trava e,
 * depois do commit do outro, decidir pelo estado COMITADO: nunca duas adoções ativas da mesma clínica, nunca
 * ativação de uma versão que acabou de ser suspensa.
 *
 * Fica em tests/Integration (DatabaseMigrations, dados comitados) e roda por caminho explícito; depois:
 * php artisan migrate:fresh --env=testing --force.
 */

use App\Domains\Recall\Enums\ProtocolVersionState as State;
use App\Domains\Recall\Exceptions\ProtocolActivationException as Refused;
use App\Domains\Recall\Models\RecallClinicProtocol;
use App\Domains\Recall\Services\{ProtocolActivationService, ProtocolGovernanceService};
use App\Models\{EntityUser, User};
use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\{DB, Notification, Process};
use Illuminate\Support\Str;
use Tests\TestCase;

uses(TestCase::class, DatabaseMigrations::class);

/** Ambiente do processo filho: mesmo banco de teste desta suíte. */
function racChildEnv(array $extra = []): array
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
 * Sobe o filho, espera ele estar com a operação feita e a transação aberta (marcador) e roda `$mainOperation` no
 * principal nessa janela.
 *
 * @return array{0: float, 1: ?Throwable, 2: ProcessResult} quanto o principal esperou, o que lançou, o resultado do filho
 */
function racRaceAgainstChild(string $mode, string $id, callable $mainOperation, array $env = [], float $hold = 2.5): array
{
    $marker = sys_get_temp_dir() . '/rac-' . Str::uuid();

    $child = Process::path(base_path())
        ->env(racChildEnv($env))
        ->timeout(60)
        ->start([PHP_BINARY, 'tests/Integration/Support/recall_activation_child.php', $mode, $id, $marker, (string) $hold]);

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

beforeEach(function () {
    Notification::fake();

    ['entity' => $this->entity, 'doctor' => $this->doctor] = rcfClinic(recall: true);
    $this->actor                                           = User::query()->findOrFail(EntityUser::query()->findOrFail($this->doctor->entity_user_id)->user_id);
    $this->version                                         = rcfReleased('glaucoma');
    $this->env                                             = [
        'RAC_ENTITY'  => (string) $this->entity->id,
        'RAC_VERSION' => (string) $this->version->id,
        'RAC_USER'    => (string) $this->actor->id,
        'RAC_DOCTOR'  => (string) $this->doctor->id,
    ];
});

it('[CORRIDA] duas ativações do mesmo protocolo: a 2ª espera a trava e atualiza a MESMA adoção — nunca duas ativas, nunca violação de índice', function () {
    [$waited, $thrown, $child] = racRaceAgainstChild(
        'activate',
        (string) $this->version->protocol_id,
        fn () => app(ProtocolActivationService::class)->activate($this->entity, (string) $this->version->protocol_id, (string) $this->version->id, ['tasks' => ['campimetria' => ['interval' => 9]]], true, $this->actor, $this->doctor),
        $this->env,
    );

    $rows = RecallClinicProtocol::query()->withoutGlobalScopes()->where('entity_id', $this->entity->id)->get();

    expect($child->successful())->toBeTrue("filho falhou — stderr: {$child->errorOutput()}")
        ->and($waited)->toBeGreaterThan(1.0)
        // A 2ª viu a adoção COMITADA e a atualizou: sem exceção, sem segunda linha.
        ->and($thrown)->toBeNull()
        ->and($rows)->toHaveCount(1)
        ->and($rows->first()->deactivated_at)->toBeNull()
        // Vale a ÚLTIMA (a do principal, que entrou depois): o filho tinha gravado 7, o principal 9.
        ->and($rows->first()->parameters['tasks']['campimetria']['interval'])->toBe(9);
});

it('[CORRIDA] suspender a versão × ativar: a ativação espera, vê a versão SUSPENSA e é recusada — nenhuma adoção nasce', function () {
    [$waited, $thrown, $child] = racRaceAgainstChild(
        'suspend',
        (string) $this->version->id,
        fn () => app(ProtocolActivationService::class)->activate($this->entity, (string) $this->version->protocol_id, (string) $this->version->id, [], true, $this->actor, $this->doctor),
        $this->env,
    );

    expect($child->successful())->toBeTrue("filho falhou — stderr: {$child->errorOutput()}")
        ->and($waited)->toBeGreaterThan(1.0)
        ->and($thrown)->toBeInstanceOf(Refused::class)
        ->and($thrown->reason())->toBe(Refused::VERSION_NOT_RELEASED)
        ->and($this->version->fresh()->state)->toBe(State::Suspended)
        ->and(RecallClinicProtocol::query()->withoutGlobalScopes()->count())->toBe(0);
});

it('[CORRIDA] ativar × suspender a versão: a suspensão espera a ativação comitar e vale — a adoção fica, parada pelo freio de mão', function () {
    [$waited, $thrown, $child] = racRaceAgainstChild(
        'activate',
        (string) $this->version->protocol_id,
        fn () => app(ProtocolGovernanceService::class)->suspend($this->version->fresh(), 'Dúvida clínica surgida enquanto a clínica ativava.'),
        $this->env,
    );

    $adoption = RecallClinicProtocol::query()->withoutGlobalScopes()->where('entity_id', $this->entity->id)->sole();

    expect($child->successful())->toBeTrue("filho falhou — stderr: {$child->errorOutput()}")
        ->and($waited)->toBeGreaterThan(1.0)
        ->and($thrown)->toBeNull()
        ->and($this->version->fresh()->state)->toBe(State::Suspended)
        // A adoção foi gravada (o filho comitou antes da suspensão) e a versão suspensa não vale mais.
        ->and($adoption->deactivated_at)->toBeNull()
        ->and($adoption->version->state->isInUse())->toBeFalse();
});

it('[CORRIDA] ativar × desativar: a desativação não vê a adoção AINDA NÃO comitada — recusada como "não está ativo", sem travar nem apagar nada', function () {
    [$waited, $thrown, $child] = racRaceAgainstChild(
        'activate',
        (string) $this->version->protocol_id,
        fn () => app(ProtocolActivationService::class)->deactivate($this->entity, (string) $this->version->protocol_id, 'Desativando em cima da ativação do colega.', $this->actor),
        $this->env,
    );

    expect($child->successful())->toBeTrue("filho falhou — stderr: {$child->errorOutput()}")
        // Nada para desativar do ponto de vista dela: não espera a transação do outro.
        ->and($waited)->toBeLessThan(1.5)
        ->and($thrown)->toBeInstanceOf(Refused::class)
        ->and($thrown->reason())->toBe(Refused::NOT_ADOPTED)
        // A ativação do filho comitou e continua valendo.
        ->and(RecallClinicProtocol::query()->withoutGlobalScopes()->where('entity_id', $this->entity->id)->whereNull('deactivated_at')->count())->toBe(1);
});

it('[CORRIDA] duas desativações: a 2ª espera a linha, relê e é recusada — um só registro de desativação', function () {
    app(ProtocolActivationService::class)->activate($this->entity, (string) $this->version->protocol_id, (string) $this->version->id, [], true, $this->actor, $this->doctor);

    [$waited, $thrown, $child] = racRaceAgainstChild(
        'deactivate',
        (string) $this->version->protocol_id,
        fn () => app(ProtocolActivationService::class)->deactivate($this->entity, (string) $this->version->protocol_id, 'Segunda desativação, que não pode valer.', $this->actor),
        $this->env,
    );

    $adoption = RecallClinicProtocol::query()->withoutGlobalScopes()->where('entity_id', $this->entity->id)->sole();

    expect($child->successful())->toBeTrue("filho falhou — stderr: {$child->errorOutput()}")
        ->and($waited)->toBeGreaterThan(1.0)
        ->and($thrown)->toBeInstanceOf(Refused::class)
        ->and($thrown->reason())->toBe(Refused::NOT_ADOPTED)
        // O motivo gravado é o do filho (que desativou primeiro), não o da que perdeu a corrida.
        ->and($adoption->deactivation_reason)->toBe('Desativado pelo processo filho do teste.');
});
