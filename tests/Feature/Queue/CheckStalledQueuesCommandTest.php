<?php

declare(strict_types=1);

use App\Enums\SaasRule;
use App\Models\{Entity, User};
use App\Notifications\QueueStalledNotification;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Contracts\Redis\Connection;
use Illuminate\Queue\RedisQueue;
use Illuminate\Support\Facades\{DB, Notification, Queue};

/**
 * queue:check-stalled: o job da frente de uma fila sem mudar além do prazo =
 * ninguém consome (08/11/2026: critical e bulk 6+ dias sem worker, sem aviso).
 */
function stalledJob(string $queue, ?int $availableAt = null): int
{
    return (int) DB::table('jobs')->insertGetId([
        'queue'        => $queue,
        'payload'      => json_encode(['uuid' => (string) str()->uuid(), 'displayName' => 'Job']),
        'attempts'     => 0,
        'reserved_at'  => null,
        'available_at' => $availableAt ?? now()->getTimestamp(),
        'created_at'   => now()->getTimestamp(),
    ]);
}

beforeEach(function () {
    Notification::fake();
    config(['queue.default' => 'database', 'queue.stalled_alert.minutes' => 15, 'queue.stalled_alert.bulk_minutes' => 45]);

    $saas        = Entity::factory()->create(['is_client' => false, 'active' => true]);
    $this->admin = User::factory()->create();
    createEntityUser($saas, $this->admin, SaasRule::Admin->value);
});

it('mesmo job na frente além do prazo: avisa o time na hora, no máximo 1x por hora', function () {
    stalledJob('critical');

    $this->artisan('queue:check-stalled')->assertSuccessful();
    Notification::assertNothingSent(); // primeira vez que vê a frente

    $this->travel(16)->minutes();
    $this->artisan('queue:check-stalled')->assertSuccessful();

    Notification::assertSentTo($this->admin, QueueStalledNotification::class, fn (QueueStalledNotification $n) => $n->queue === 'critical' && $n->minutes === 16);

    $this->travel(5)->minutes();
    $this->artisan('queue:check-stalled');

    Notification::assertSentToTimes($this->admin, QueueStalledNotification::class, 1);
});

it('fila andando (a frente muda) não avisa', function () {
    $first = stalledJob('default');
    $this->artisan('queue:check-stalled');

    $this->travel(10)->minutes();
    DB::table('jobs')->where('id', $first)->delete(); // consumido
    stalledJob('default');
    $this->artisan('queue:check-stalled');

    $this->travel(10)->minutes();
    $this->artisan('queue:check-stalled');

    Notification::assertNothingSent();
});

it('bulk tem prazo maior: job longo de 15 min com outro esperando não é alarme', function () {
    stalledJob('bulk');
    $this->artisan('queue:check-stalled');

    $this->travel(30)->minutes();
    $this->artisan('queue:check-stalled');
    Notification::assertNothingSent();

    $this->travel(16)->minutes();
    $this->artisan('queue:check-stalled');
    Notification::assertSentTo($this->admin, QueueStalledNotification::class, fn (QueueStalledNotification $n) => $n->queue === 'bulk');
});

it('fila vazia ou só com job adiado (backoff) não avisa', function () {
    stalledJob('critical', now()->addHour()->getTimestamp());

    $this->artisan('queue:check-stalled');
    $this->travel(20)->minutes();
    $this->artisan('queue:check-stalled');

    Notification::assertNothingSent();
});

it('fila síncrona (sync): nada a vigiar', function () {
    config(['queue.default' => 'sync']);

    $this->artisan('queue:check-stalled')->assertSuccessful();

    Notification::assertNothingSent();
});

it('Redis: lê a frente de queues:{fila} (índice 0)', function () {
    config(['queue.default' => 'redis']);

    $payload = json_encode(['uuid' => 'job-parado']);
    $redis   = Mockery::mock(Connection::class);
    $redis->shouldReceive('lindex')->andReturnUsing(fn (string $key, int $index) => $key === 'queues:critical' && $index === 0 ? $payload : null);
    $queue = Mockery::mock(RedisQueue::class);
    $queue->shouldReceive('getConnection')->andReturn($redis);
    $queue->shouldReceive('getQueue')->andReturnUsing(fn (string $name) => 'queues:' . $name);
    Queue::partialMock()->shouldReceive('connection')->with('redis')->andReturn($queue);

    $this->artisan('queue:check-stalled');
    $this->travel(16)->minutes();
    $this->artisan('queue:check-stalled');

    Notification::assertSentTo($this->admin, QueueStalledNotification::class, fn (QueueStalledNotification $n) => $n->queue === 'critical');
    Notification::assertSentToTimes($this->admin, QueueStalledNotification::class, 1);
});

it('o e-mail não passa pela fila (ela pode ser a parada)', function () {
    expect(new QueueStalledNotification('critical', 20))->not->toBeInstanceOf(ShouldQueue::class);
});
