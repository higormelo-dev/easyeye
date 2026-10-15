<?php

declare(strict_types=1);

/**
 * Processo FILHO do RecallExamMappingConcurrencyTest: "outra requisição" que salva o mapeamento de exames de uma clínica
 * numa sessão PostgreSQL própria. Faz o salvamento de verdade (`ExamMappingService::sync`) dentro de uma transação e
 * PAUSA no meio: logo depois de ler o conjunto atual para substituí-lo (o `FOR UPDATE` do mapeamento) e ANTES de gravar —
 * a janela em que o principal salva também. A pausa mora num ouvinte de consultas, então o código executado é o do
 * produto; quando a pausa acaba, o salvamento termina e a transação comita.
 *
 * Uso: php recall_mapping_child.php <clinica> <arquivo_marcador> <segundos_de_pausa>
 * Variável: RM_MAPPINGS (JSON: chave canônica → ids dos tipos de exame). Em caso de sucesso imprime "OK".
 */

use App\Domains\Recall\Services\ExamMappingService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

require __DIR__ . '/../../../vendor/autoload.php';

$app = require __DIR__ . '/../../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

[, $entityId, $marker, $holdSeconds] = $argv;

try {
    DB::transaction(function () use ($entityId, $marker, $holdSeconds): void {
        $paused = false;

        DB::listen(function ($query) use (&$paused, $marker, $holdSeconds): void {
            if (! $paused && str_contains($query->sql, 'from "recall_exam_mappings"') && str_contains($query->sql, 'for update')) {
                $paused = true;

                file_put_contents($marker, 'holding');
                usleep((int) ((float) $holdSeconds * 1_000_000));
            }
        });

        app(ExamMappingService::class)->sync($entityId, (array) json_decode((string) getenv('RM_MAPPINGS'), true, 512, JSON_THROW_ON_ERROR));
    });

    fwrite(STDOUT, "OK\n");
} catch (Throwable $e) {
    fwrite(STDERR, $e::class . ': ' . $e->getMessage() . "\n");

    exit(1);
}
