<?php

declare(strict_types=1);

/**
 * Fase 4 / M4 — concorrência REAL (duas conexões PostgreSQL) nos pontos que o
 * dinheiro e a rastreabilidade não perdoam:
 *
 *  1. duas cirurgias disputando a ÚLTIMA unidade do lote: uma baixa, a outra
 *     recebe 422 de saldo, o saldo nunca fica negativo e só existe um implante;
 *  2. a MESMA série em dois implantes ao mesmo tempo: um vence, o outro é
 *     recusado (422, não 500) e a baixa do perdedor não fica;
 *  3. "registrar como realizada" duas vezes ao mesmo tempo: um efeito só.
 *
 * O filho executa o service e segura a transação; o principal faz a operação
 * concorrente pelo HTTP. Fica em tests/Integration (DatabaseMigrations, dados
 * comitados) e roda por caminho explícito.
 */

require_once __DIR__ . '/../Feature/Surgery/OperationFixtures.php';

use App\Enums\StockMovementType;
use App\Enums\Surgery\SurgicalCaseStatus;
use App\Models\StockMovement;
use App\Models\Surgery\{SurgicalImplant, SurgicalOperation};
use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\{DB, Process};
use Illuminate\Support\Str;
use Tests\TestCase;

uses(TestCase::class, DatabaseMigrations::class);

/** Ambiente do processo filho: mesmo banco de teste desta suíte. */
function sopcChildEnv(): array
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

/**
 * Sobe o filho, espera ele chegar à pausa, roda `$main` (o principal) e espera o filho terminar.
 *
 * @param callable(): mixed $main
 *
 * @return array{main: mixed, child: ProcessResult}
 */
function sopcRace(string $action, string $caseId, string $doctorId, array $payload, callable $main, float $hold = 2.5): array
{
    $marker = sys_get_temp_dir() . '/sopc-' . Str::uuid();

    $child = Process::path(base_path())
        ->env(sopcChildEnv())
        ->timeout(90)
        ->start([PHP_BINARY, 'tests/Integration/Support/operation_child.php', $action, $caseId, $doctorId, $marker, (string) $hold, json_encode($payload, JSON_THROW_ON_ERROR)]);

    try {
        $deadline = microtime(true) + 40;

        while (! is_file($marker) && microtime(true) < $deadline && $child->running()) {
            usleep(50_000);
        }

        expect(is_file($marker))->toBeTrue("o filho não chegou à pausa — stdout: {$child->output()} | stderr: {$child->errorOutput()}");

        // Rede de segurança contra pendurar a suíte se alguém travar de verdade.
        DB::statement("set statement_timeout = '25s'");

        $result = $main();
        $done   = $child->wait();
    } finally {
        @unlink($marker);
    }

    return ['main' => $result, 'child' => $done];
}

it('[GAP] duas cirurgias disputam a ÚLTIMA unidade do lote: uma baixa, a outra leva 422 e o saldo nunca fica negativo', function () {
    $w               = surgeryWorld();
    [$product, $lot] = sopIol($w, qty: 1);
    $caseA           = sopCase($w, ['title' => 'A']);
    $caseB           = sopCase($w, ['title' => 'B']);

    // Filho: caso B — segura a transação depois da baixa (travas de produto e lote na mão).
    ['main' => $response, 'child' => $child] = sopcRace(
        'register-after-stock',
        (string) $caseB->id,
        (string) $w->doctor->id,
        sopImplantPayload($product, $lot, ['serial_number' => 'SN-B']),
        fn () => sopStore((object) ['w' => $w], $caseA, sopImplantPayload($product, $lot, ['serial_number' => 'SN-A'])),
    );

    expect($child->successful())->toBeTrue("filho falhou — stderr: {$child->errorOutput()}")
        // O principal esperou a trava, releu o saldo (0) e foi recusado: 422, não 500, não deadlock.
        ->and($response->getStatusCode())->toBe(422)
        ->and($child->errorOutput())->not->toContain('40P01');

    expect((float) $lot->fresh()->qty_on_hand)->toBe(0.0)
        ->and((float) $product->fresh()->qty_on_hand)->toBe(0.0)
        ->and(StockMovement::query()->where('type', StockMovementType::ConsumptionOut->value)->count())->toBe(1)
        ->and(SurgicalImplant::query()->count())->toBe(1)
        ->and(SurgicalImplant::query()->firstOrFail()->serial_number)->toBe('SN-B');
});

it('[GAP] a mesma série em dois implantes ao mesmo tempo: um vence, o outro é recusado e a baixa dele não fica', function () {
    $w               = surgeryWorld();
    [$product, $lot] = sopIol($w, qty: 5);
    $caseA           = sopCase($w, ['title' => 'A']);
    $caseB           = sopCase($w, ['title' => 'B']);

    // Filho: caso B — segura logo depois do lock da série (antes de baixar o estoque).
    ['main' => $response, 'child' => $child] = sopcRace(
        'register-after-serial',
        (string) $caseB->id,
        (string) $w->doctor->id,
        sopImplantPayload($product, $lot, ['serial_number' => 'SN-IGUAL']),
        fn () => sopStore((object) ['w' => $w], $caseA, sopImplantPayload($product, $lot, ['serial_number' => 'sn-igual'])),
    );

    expect($child->successful())->toBeTrue("filho falhou — stderr: {$child->errorOutput()}")
        ->and($response->getStatusCode())->toBe(422)
        ->and($response->json('message'))->toContain('SN-IGUAL');

    $implants = SurgicalImplant::query()->get();

    expect($implants)->toHaveCount(1)
        ->and((string) $implants->first()->surgical_case_id)->toBe((string) $caseB->id)
        // A baixa do perdedor não ficou: só uma unidade saiu.
        ->and((float) $lot->fresh()->qty_on_hand)->toBe(4.0)
        ->and(StockMovement::query()->where('type', StockMovementType::ConsumptionOut->value)->count())->toBe(1);
});

it('[GAP] registrar a cirurgia como realizada duas vezes ao mesmo tempo tem um efeito só', function () {
    $w    = surgeryWorld();
    $case = sopCase($w);

    // A Pausa Cirúrgica (condição registrada em config) segue valendo: o cirurgião assume a exceção.
    $body = ['team_confirmed' => true, 'time_out_override_reason' => 'Urgência: paciente já anestesiado na sala', 'consent_override_reason' => 'TCLE assinado em papel, digitalização pendente'];

    // Filho: segura a transação depois de gravar o ato (linha do caso travada).
    ['main' => $response, 'child' => $child] = sopcRace(
        'perform-after-write',
        (string) $case->id,
        (string) $w->doctor->id,
        $body,
        fn () => surgeryAs($w, 'doctor')->postJson(route('panel.surgery.operation.perform', $case), $body),
    );

    expect($child->successful())->toBeTrue("filho falhou — stderr: {$child->errorOutput()}")
        // O principal esperou a trava, releu o estado (Realizada) e foi recusado sem gravar nada.
        ->and($response->getStatusCode())->toBe(409);

    $case->refresh();

    expect($case->status)->toBe(SurgicalCaseStatus::Performed)
        ->and(SurgicalOperation::query()->where('surgical_case_id', $case->id)->count())->toBe(1)
        ->and(collect($case->status_history)->where('to', 'performed')->count())->toBe(1);
});
