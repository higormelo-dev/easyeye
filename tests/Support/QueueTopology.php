<?php

declare(strict_types=1);

namespace Tests\Support;

use FilesystemIterator;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Process;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionClass;

/**
 * Leitura estática da topologia de filas (Supervisor, entrypoint, jobs) para WorkerTopologyTest (roda na CI) e
 * QueueWorkerSmokeTest (worker real). Classe, não funções globais: os dois arquivos podem rodar no mesmo processo.
 */
final class QueueTopology
{
    public const SUPERVISOR_CONF = '.docker/supervisor/easyeye-worker.conf';

    public const ENTRYPOINT = '.docker/php/entrypoint.sh';

    /**
     * Os programas do Supervisor, por regex de bloco (`parse_ini_file` falha com o `%(program_name)s` do arquivo).
     *
     * @return array<string, array{command: string, connection: string, queues: list<string>, timeout: int, tries: int, memory: int, numprocs: int, stopwaitsecs: int}>
     */
    public static function programs(): array
    {
        $conf = (string) file_get_contents(base_path(self::SUPERVISOR_CONF));

        preg_match_all('/^\[program:([^\]]+)\]\R(.*?)(?=^\[program:|\z)/ms', $conf, $blocks, PREG_SET_ORDER);

        $programs = [];

        foreach ($blocks as [, $name, $body]) {
            preg_match('/^command=(.+)$/m', $body, $command);

            $line = $command[1] ?? '';

            $programs[$name] = [
                'command'      => $line,
                'connection'   => self::find('/queue:work\s+(\S+)/', $line),
                'queues'       => array_values(array_filter(explode(',', self::find('/--queue=(\S+)/', $line)))),
                'timeout'      => (int) self::find('/--timeout=(\d+)/', $line),
                'tries'        => (int) self::find('/--tries=(\d+)/', $line),
                'memory'       => (int) self::find('/--memory=(\d+)/', $line),
                'numprocs'     => (int) self::find('/^numprocs=(\d+)/m', $body),
                'stopwaitsecs' => (int) self::find('/^stopwaitsecs=(\d+)/m', $body),
            ];
        }

        return $programs;
    }

    /** Programas que consomem a conexão do fast (critical, default, tiss-*). @return array<string, array<string, mixed>> */
    public static function fastPrograms(): array
    {
        return array_filter(self::programs(), fn (string $name): bool => str_starts_with($name, 'easyeye-worker-fast'), ARRAY_FILTER_USE_KEY);
    }

    /**
     * Filas que o fast precisa consumir: todas as configuradas, menos a do trabalho longo.
     *
     * @return list<string>
     */
    public static function configuredFastQueues(): array
    {
        return array_values(array_unique(array_map('strval', [
            config('queue.connections.redis.queue'),
            config('whatsapp.queue'), config('whatsapp.verification_queue'), config('whatsapp.inbound_queue'),
            config('billing.webhooks.queue'), config('billing.jobs.queue'),
            config('ai.jobs.queue'), config('fiscal.queue'),
            config('tiss.queues.xml'), config('tiss.queues.send'), config('tiss.queues.returns'),
        ])));
    }

    /**
     * Maior $timeout declarado (propriedade literal, não calculado no construtor) entre as classes de job do escopo:
     * `fast` (tudo menos o bulk), `bulk` (jobs que despacham para config('queue.bulk_queue')) ou `all`.
     */
    public static function maxDeclaredJobTimeout(string $scope = 'all'): int
    {
        $maxTimeout = 60; // default implícito do Laravel pra job sem $timeout próprio

        // Job que calcula o timeout no construtor (RunAiWorkflowJob, a partir de config) não tem valor acessível sem
        // rodar o construtor de verdade: entra aqui pela config que o alimenta (só nos escopos que o servem).
        if ($scope !== 'bulk') {
            $maxTimeout = max($maxTimeout, (int) config('ai.jobs.timeout_seconds', 180));
        }

        foreach (self::jobClasses() as $class) {
            $ref = new ReflectionClass($class);

            if (! $ref->hasProperty('timeout')) {
                continue;
            }

            $isBulk = str_contains((string) file_get_contents((string) $ref->getFileName()), 'queue.bulk_queue');

            if (($scope === 'bulk' && ! $isBulk) || ($scope === 'fast' && $isBulk)) {
                continue;
            }

            $instance = $ref->newInstanceWithoutConstructor();
            $property = $ref->getProperty('timeout');

            if (! $property->isInitialized($instance)) {
                continue;
            }

            $timeout = $property->getValue($instance);

            if (is_int($timeout) && $timeout > $maxTimeout) {
                $maxTimeout = $timeout;
            }
        }

        return $maxTimeout;
    }

    /** @return list<class-string> */
    public static function jobClasses(): array
    {
        $classes  = [];
        $root     = app_path();
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));

        foreach ($iterator as $file) {
            if (! $file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }

            $class = 'App\\' . str_replace('/', '\\', substr($file->getPathname(), strlen($root) + 1, -4));

            if (! class_exists($class)) {
                continue;
            }

            $ref = new ReflectionClass($class);

            if ($ref->isAbstract() || $ref->isInterface() || ! $ref->implementsInterface(ShouldQueue::class)) {
                continue;
            }

            $classes[] = $class;
        }

        return $classes;
    }

    /**
     * O que o entrypoint EXPORTA como SUPERVISOR_BULK_CONNECTION para uma QUEUE_CONNECTION (null = variável ausente) e o
     * que escreve no stderr. Executa só o bloco `case ... esac` + `export` do arquivo, num `sh` com ambiente limpo (nada
     * de composer/artisan); o valor é lido por um `sh` FILHO, então só aparece se foi exportado de verdade.
     *
     * @return array{value: string, stderr: string}
     */
    public static function entrypointBulkConnection(?string $queueConnection): array
    {
        $script = (string) file_get_contents(base_path(self::ENTRYPOINT));

        if (! preg_match('/^case "\$\{QUEUE_CONNECTION[^\n]*\n.*?^esac\nexport SUPERVISOR_BULK_CONNECTION$/ms', $script, $block)) {
            return ['value' => 'bloco case/esac + export não encontrado em ' . self::ENTRYPOINT, 'stderr' => ''];
        }

        $result = Process::env($queueConnection === null ? [] : ['QUEUE_CONNECTION' => $queueConnection])
            ->run(['env', '-u', 'SUPERVISOR_BULK_CONNECTION', ...($queueConnection === null ? ['-u', 'QUEUE_CONNECTION'] : []), 'sh', '-c', $block[0] . "\nsh -c 'printf %s \"\$SUPERVISOR_BULK_CONNECTION\"'"]);

        return ['value' => trim($result->output()), 'stderr' => trim($result->errorOutput())];
    }

    private static function find(string $pattern, string $subject): string
    {
        preg_match($pattern, $subject, $matches);

        return $matches[1] ?? '';
    }
}
