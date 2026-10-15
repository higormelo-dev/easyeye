<?php

declare(strict_types=1);

/**
 * M3b — corridas REAIS (duas conexões PostgreSQL, dados comitados) do TISS do caso cirúrgico:
 *
 *  1. duas requisições de ENVIO da mesma guia → UMA transmissão e UMA chamada ao transporte
 *     (a 2ª encontra a 1ª em voo; vale também na retomada de uma entrega em dúvida);
 *  2. duas requisições de GERAR a mesma guia → UMA guia (o caso é travado; o índice único
 *     parcial é a rede de segurança).
 *
 * Fica em tests/Integration (DatabaseMigrations) e roda por caminho explícito.
 */

use App\Domains\Tiss\Enums\{TissGuideType, TissTransmissionStatus};
use App\Domains\Tiss\Models\{TissGuide, TissTransmission};
use App\Domains\Tiss\Surgical\{SendSurgicalGuideService, SurgicalGuideService};
use App\Exceptions\Surgery\SurgeryRuleException;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Process\InvokedProcess;
use Illuminate\Support\Facades\{DB, Process};
use Tests\TestCase;

uses(TestCase::class, DatabaseMigrations::class);

require_once __DIR__ . '/../Support/TissSurgicalFixtures.php';

/** Ambiente do processo filho: mesmo banco de teste desta suíte. */
function stiChildEnv(): array
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

/** Sobe o filho e espera ele chegar à pausa (arquivo marcador). */
function stiStartChild(string $mode, string $id, string $marker, string $seconds): InvokedProcess
{
    $child = Process::path(base_path())
        ->env(stiChildEnv())
        ->timeout(90)
        ->start([PHP_BINARY, 'tests/Integration/Support/tiss_surgical_child.php', $mode, $id, $marker, $seconds]);

    $deadline = microtime(true) + 40;

    while (! is_file($marker) && microtime(true) < $deadline && $child->running()) {
        usleep(50_000);
    }

    expect(is_file($marker))->toBeTrue("o filho não chegou à pausa — stdout: {$child->output()} | stderr: {$child->errorOutput()}");

    return $child;
}

it('duas requisições de envio da mesma guia: UMA transmissão e UMA chamada ao transporte', function (string $start) {
    $w     = stcWorld();
    $guide = stcReadyGuide($w);
    $fake  = stcFakeTransport();

    if ($start === 'doubt') {
        // Entrega em dúvida pendente: a retomada também não pode duplicar.
        $fake->queue = [StcFakeTransport::noResponse()];

        try {
            app(SendSurgicalGuideService::class)->send($guide->refresh());
        } catch (SurgeryRuleException) {
        }

        expect(TissTransmission::query()->value('status'))->toBe(TissTransmissionStatus::Timeout);
    }

    $callsBefore = count($fake->calls);
    $marker      = sys_get_temp_dir() . '/tiss-send-' . uniqid() . '.marker';
    $child       = stiStartChild('send-slow', (string) $guide->id, $marker, '3');

    try {
        DB::statement("set statement_timeout = '20s'");

        $message = null;

        try {
            app(SendSurgicalGuideService::class)->send($guide->refresh());
        } catch (SurgeryRuleException $e) {
            $message = $e->getMessage();
        }

        $childResult = $child->wait();
    } finally {
        @unlink($marker);
    }

    // A 2ª requisição foi barrada pela transmissão em voo da 1ª — sem tocar o transporte.
    expect($message)->toBe(__('surgery_tiss.errors.transmission_in_progress'))
        ->and(count($fake->calls))->toBe($callsBefore)
        ->and($childResult->successful())->toBeTrue("filho falhou — stderr: {$childResult->errorOutput()}")
        ->and($childResult->output())->toContain('sent:acknowledged')
        ->and(TissTransmission::query()->where('guide_id', $guide->id)->count())->toBe(1)
        ->and(TissTransmission::query()->where('guide_id', $guide->id)->value('status'))->toBe(TissTransmissionStatus::Acknowledged)
        ->and($childResult->errorOutput())->not->toContain('40P01');
})->with([
    'primeiro envio'                    => 'first',
    'retomada de uma entrega em dúvida' => 'doubt',
]);

it('duas requisições de GERAR a mesma guia: UMA guia (a 2ª espera a trava do caso e devolve a da 1ª)', function () {
    $w      = stcWorld();
    $marker = sys_get_temp_dir() . '/tiss-gen-' . uniqid() . '.marker';
    $child  = stiStartChild('generate-hold', (string) $w->case->id, $marker, '2.5');

    try {
        DB::statement("set statement_timeout = '20s'");

        $second = app(SurgicalGuideService::class)->generate($w->case->refresh(), TissGuideType::InpatientRequest);

        $childResult = $child->wait();
    } finally {
        @unlink($marker);
    }

    expect($childResult->successful())->toBeTrue("filho falhou — stderr: {$childResult->errorOutput()}")
        ->and($childResult->output())->toContain('guide:' . $second->id)
        ->and(TissGuide::query()->where('surgical_case_id', $w->case->id)->where('guide_type', 'inpatient_request')->count())->toBe(1);
});
