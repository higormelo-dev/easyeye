<?php

declare(strict_types=1);

use Illuminate\Console\Scheduling\{CallbackEvent, Event, Schedule};

/**
 * F1: o agendador não pode ficar preso numa tarefa lenta (gateway, WhatsApp, Focus) nem travar uma tarefa frequente por
 * 24 h depois de um kill. Tabela única das ~40 entradas de routes/console.php: o que roda em segundo plano, o prazo do
 * mutex (`withoutOverlapping`, em minutos; 1440 = padrão de 24 h) e a expressão cron (não pode mudar sem querer).
 * O mutex em segundo plano é liberado no fim de cada execução (schedule:finish); o prazo só vale se o processo morrer.
 */

/** @return array<string, Event> nome => evento */
function scheduledEventsByName(): array
{
    $events = [];

    foreach (app(Schedule::class)->events() as $event) {
        $events[(string) ($event->description ?: $event->command)] = $event;
    }

    return $events;
}

// nome => [cron, runInBackground, prazo do mutex em minutos]
const SCHEDULE_TABLE = [
    'trials:expire'                 => ['5 0 * * *', false, 1440],
    'subscriptions:expire'          => ['10 0 * * *', false, 1440],
    'subscriptions:plan-changes'    => ['15 0 * * *', false, 1440],
    'subscriptions:renew'           => ['0 1 * * *', false, 1440],
    'ai:grant-monthly-quotas'       => ['20 0 * * *', false, 1440],
    'billing:reconcile-overdue'     => ['30 8 * * *', true, 1440],
    'billing:check-refunds'         => ['0 * * * *', true, 10],
    'billing:gateway-health'        => ['10 6 * * *', true, 1440],
    'billing:dunning'               => ['0 9 * * *', true, 1440],
    'billing:trial-notices'         => ['10 9 * * *', false, 1440],
    'ai:notify-waiting-approval'    => ['0 7 * * *', false, 1440],
    'clinical-review:expire'        => ['20 3 * * *', false, 1440],
    'recall:verify-integrity'       => ['15 5 * * *', true, 1440],
    'recall:evaluate-dirty'         => ['* * * * *', true, 10],
    'recall:reconcile'              => ['30 2 * * *', true, 1440],
    'recall:send-invites'           => ['5 * * * *', true, 30],
    'recall:requeue-pending'        => ['*/10 * * * *', false, 10],
    'clinic-invitations:expire'     => ['30 3 * * *', false, 1440],
    'medical-records:prune-drafts'  => ['40 3 * * *', false, 1440],
    'ai:purge-feedbacks'            => ['0 3 * * 0', false, 1440],
    'ai:expire-stale-runs'          => ['0 * * * *', false, 10],
    'whatsapp:send-confirmations'   => ['0 * * * *', true, 10],
    'whatsapp:sweep-stuck'          => ['*/10 * * * *', false, 10],
    'covenants:sync-ans'            => ['30 4 * * 1', false, 1440],
    'medicines:sync-cmed'           => ['0 5 * * 2', false, 1440],
    'ai:sync-model-catalog'         => ['40 3 * * *', false, 1440],
    'whatsapp:send-surveys'         => ['0 * * * *', true, 10],
    'stock:check-alerts'            => ['30 6 * * *', false, 1440],
    'queue-health:prune-history'    => ['30 3 * * *', false, 1440],
    'integrator-commands:prune'     => ['45 3 * * 1', false, 1440],
    'billing:prune-webhook-events'  => ['50 3 * * *', false, 1440],
    'ai:expire-credit-pack-orders'  => ['10 4 * * *', false, 1440],
    'integrator-outbox:publish'     => ['* * * * *', true, 10],
    'surgery:expire-authorizations' => ['25 0 * * *', false, 1440],
    'fiscal:nfse-reissue'           => ['10 5 * * *', true, 1440],
    'fiscal:nfse-sync'              => ['*/10 * * * *', true, 10],
    'queue:prune-failed'            => ['20 4 * * *', false, 10],
    'ai:fetch-exchange-rate'        => ['5 6 * * *', true, 10],
];

it('tem exatamente as tarefas esperadas, cada uma com nome', function () {
    expect(array_keys(scheduledEventsByName()))->toEqualCanonicalizing(array_keys(SCHEDULE_TABLE));
});

it('cada tarefa tem o cron, o segundo plano e o prazo de mutex combinados, e roda em um servidor só', function (string $name, string $cron, bool $background, int $mutexMinutes) {
    $event = scheduledEventsByName()[$name];

    expect($event->expression)->toBe($cron)
        ->and($event->runInBackground)->toBe($background)
        ->and($event->withoutOverlapping)->toBeTrue()
        ->and($event->expiresAt)->toBe($mutexMinutes)
        ->and($event->onOneServer)->toBeTrue();
})->with(array_map(null, array_keys(SCHEDULE_TABLE), array_column(SCHEDULE_TABLE, 0), array_column(SCHEDULE_TABLE, 1), array_column(SCHEDULE_TABLE, 2)));

it('tarefa em segundo plano é sempre um comando artisan (callback não suporta runInBackground)', function () {
    foreach (scheduledEventsByName() as $name => $event) {
        if ($event->runInBackground) {
            expect($event)->not->toBeInstanceOf(CallbackEvent::class);
        }
    }
});

it('faz a limpeza diária de falhas de job com 30 dias de retenção', function () {
    $event = scheduledEventsByName()['queue:prune-failed'];

    expect($event->command)->toContain('queue:prune-failed --hours=720');
});

it('horário comercial e chaves de liga/desliga seguem valendo (between/when preservados)', function (string $name) {
    $filters = fn (Event $event): int => count((new ReflectionProperty($event, 'filters'))->getValue($event));

    expect($filters(scheduledEventsByName()[$name]))->toBeGreaterThan(0);
})->with([
    'whatsapp:send-confirmations', 'whatsapp:send-surveys', 'covenants:sync-ans', 'medicines:sync-cmed', 'ai:sync-model-catalog',
]);
