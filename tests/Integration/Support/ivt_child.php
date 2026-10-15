<?php

declare(strict_types=1);

/**
 * Processo FILHO do IntravitrealConcurrencyTest: "outra requisição" do curso de injeções intravítreas numa sessão
 * PostgreSQL própria. Faz a operação DENTRO de uma transação, avisa o principal pelo arquivo marcador e SEGURA a
 * transação por alguns segundos antes de comitar — a janela em que o principal tenta a operação concorrente. As travas
 * (prontuário, procedimento, paciente, curso) ficam seguras até o commit, porque as transações do serviço aninham na
 * deste processo.
 *
 * Uso: php ivt_child.php <modo> <id> <arquivo_marcador> <segundos_de_pausa>
 *   modos (id = procedimento): ensure · execute
 *         (id = curso):        close · schedule_next
 * Variáveis: IVT_ENTITY (clínica) e IVT_EU (vínculo do médico, para execute). Em caso de sucesso imprime "OK".
 *
 * `schedule_next` chama o agendar-próxima de verdade e SEGURA a transação dele logo depois de travar a linha do curso
 * (gancho no `DB::listen`: o evento sai depois da consulta, com a trava já tomada) — a janela em que a execução da
 * mesma aplicação tenta entrar. Sem agendamento de referência o serviço recusa e desfaz; o que o teste mede é se as
 * duas operações se cruzaram (40P01 sobe como exceção e o processo sai com erro).
 */

use App\Domains\Recall\Intravitreal\Enums\CourseStatus;
use App\Domains\Recall\Intravitreal\Services\{IntravitrealApplicationService, IntravitrealCourseService};
use App\Models\{EntityUser, MedicalRecordProcedure};
use App\Services\MedicalRecordProcedureExecutionService;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

require __DIR__ . '/../../../vendor/autoload.php';

$app = require __DIR__ . '/../../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

[, $mode, $id, $marker, $holdSeconds] = $argv;

try {
    if ($mode === 'schedule_next') {
        $held = false;

        DB::listen(function ($query) use (&$held, $marker, $holdSeconds): void {
            $sql = strtolower($query->sql);

            if (! $held && str_contains($sql, 'from "intravitreal_courses"') && str_contains($sql, 'for update')) {
                $held = true;
                file_put_contents($marker, 'holding');
                usleep((int) ((float) $holdSeconds * 1_000_000));
            }
        });

        $result = app(IntravitrealApplicationService::class)->scheduleNext((string) getenv('IVT_ENTITY'), $id, CarbonImmutable::parse('2099-01-05 09:15'));

        fwrite(STDOUT, 'OK ' . json_encode($result) . "\n");

        exit(0);
    }

    DB::transaction(function () use ($mode, $id, $marker, $holdSeconds): void {
        $entityId = (string) getenv('IVT_ENTITY');

        switch ($mode) {
            case 'ensure':
                $procedure = MedicalRecordProcedure::query()->withoutGlobalScopes()->findOrFail($id);
                app(IntravitrealCourseService::class)->ensureForProcedure($procedure, (string) $procedure->eye, ['drug_key' => 'aflibercept']);

                break;
            case 'execute':
                $procedure = MedicalRecordProcedure::query()->withoutGlobalScopes()->findOrFail($id);
                app(MedicalRecordProcedureExecutionService::class)->markDone(
                    $procedure,
                    EntityUser::query()->withoutGlobalScopes()->findOrFail((string) getenv('IVT_EU')),
                    [],
                    null,
                    ['lot_number' => 'LOT-FILHO', 'lot_expiry' => now()->addYear()->toDateString(), 'next_interval_weeks' => 4],
                );

                break;
            case 'close':
                app(IntravitrealCourseService::class)->close($entityId, $id, CourseStatus::Discontinued, 'Encerrado pelo processo filho do teste.');

                break;
            default:
                throw new InvalidArgumentException("modo desconhecido: {$mode}");
        }

        file_put_contents($marker, 'holding');
        usleep((int) ((float) $holdSeconds * 1_000_000));
    });

    fwrite(STDOUT, "OK\n");
} catch (Throwable $e) {
    fwrite(STDERR, $e::class . ': ' . $e->getMessage() . "\n");

    exit(1);
}
