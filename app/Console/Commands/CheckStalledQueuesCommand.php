<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Notifications\QueueStalledNotification;
use App\Support\Billing\NoticeLocale;
use App\Support\SaasAlertRecipients;
use Illuminate\Console\Command;
use Illuminate\Queue\RedisQueue;
use Illuminate\Support\Facades\{Cache, DB, Log, Queue};
use Throwable;

/**
 * Fila parada: o job da FRENTE de uma fila não muda há mais que o prazo
 * (config queue.stalled_alert) = ninguém consome — worker caído, ou o crontab
 * sem esta fila no --queue. Foi o que aconteceu em 08/11/2026: a critical
 * (código do cadastro) e a bulk (importações, sync ANS/CMED) ficaram 6+ dias
 * paradas sem nenhum aviso.
 *
 * Mede a frente da fila, não a idade do job: job devolvido por backoff volta
 * com o createdAt antigo e daria falso alarme. Fila andando troca a frente.
 *
 * Avisa com Log::critical (Sentry) e e-mail ao time enviado NA HORA (sem
 * passar pela fila, que pode ser justamente a parada), no máximo 1x/h por fila.
 */
class CheckStalledQueuesCommand extends Command
{
    protected $signature = 'queue:check-stalled';

    protected $description = 'Avisa o time quando uma fila tem job parado na frente (sem worker consumindo)';

    public function handle(): int
    {
        $connection = (string) config('queue.default');
        $driver     = (string) config("queue.connections.{$connection}.driver");

        // sync/null: não há fila a vigiar.
        if (! in_array($driver, ['redis', 'database'], true)) {
            return self::SUCCESS;
        }

        foreach ($this->queues($connection) as $queue) {
            $key  = "queue:stalled:{$connection}:{$queue}";
            $head = $this->head($connection, $driver, $queue);

            if ($head === null) {
                Cache::forget($key);

                continue;
            }

            $seen = Cache::get($key);

            if (! is_array($seen) || ($seen['head'] ?? null) !== $head) {
                Cache::put($key, ['head' => $head, 'since' => now()->getTimestamp()], now()->addDay());

                continue;
            }

            $minutes = intdiv(now()->getTimestamp() - (int) $seen['since'], 60);

            if ($minutes >= $this->limitMinutes($queue)) {
                $this->notifyTeam($queue, $minutes);
            }
        }

        return self::SUCCESS;
    }

    /**
     * Filas para onde o código manda jobs (as mesmas que os workers do runbook
     * filas-workers.md precisam consumir).
     *
     * @return list<string>
     */
    private function queues(string $connection): array
    {
        return array_values(array_unique(array_filter(array_map('strval', [
            config("queue.connections.{$connection}.queue"),
            config('queue.bulk_queue'),
            config('whatsapp.queue'), config('whatsapp.verification_queue'), config('whatsapp.inbound_queue'),
            config('billing.webhooks.queue'), config('billing.jobs.queue'),
            config('ai.jobs.queue'), config('fiscal.queue'),
            config('tiss.queues.xml'), config('tiss.queues.send'), config('tiss.queues.returns'),
        ]))));
    }

    /** Identidade do job da frente (pronto para rodar), ou null com a fila vazia. */
    private function head(string $connection, string $driver, string $queue): ?string
    {
        if ($driver === 'database') {
            $id = DB::connection(config("queue.connections.{$connection}.connection"))
                ->table((string) config("queue.connections.{$connection}.table", 'jobs'))
                ->where('queue', $queue)
                ->whereNull('reserved_at')
                ->where('available_at', '<=', now()->getTimestamp())
                ->orderBy('id')
                ->value('id');

            return $id === null ? null : (string) $id;
        }

        /** @var RedisQueue $redis */
        $redis = Queue::connection($connection);
        // Os jobs prontos ficam em queues:{fila} (o atrasado e o reservado, em outras chaves); o mais antigo no índice 0.
        $payload = $redis->getConnection()->lindex($redis->getQueue($queue), 0);

        if (! is_string($payload) || $payload === '') {
            return null;
        }

        $job = json_decode($payload, true);

        return (string) ($job['uuid'] ?? $job['id'] ?? md5($payload));
    }

    private function limitMinutes(string $queue): int
    {
        return $queue === (string) config('queue.bulk_queue')
            ? max(1, (int) config('queue.stalled_alert.bulk_minutes', 45))
            : max(1, (int) config('queue.stalled_alert.minutes', 15));
    }

    private function notifyTeam(string $queue, int $minutes): void
    {
        Log::critical('[queue:alert] fila parada', ['queue' => $queue, 'minutes' => $minutes]);
        $this->warn("Fila {$queue}: o mesmo job está na frente há {$minutes} min.");

        if (! Cache::add("queue:stalled:alerted:{$queue}", 1, now()->addHour())) {
            return;
        }

        foreach (SaasAlertRecipients::get() as $user) {
            try {
                $user->notifyNow((new QueueStalledNotification($queue, $minutes))->locale(NoticeLocale::for($user)));
            } catch (Throwable $e) {
                report($e);
            }
        }
    }
}
