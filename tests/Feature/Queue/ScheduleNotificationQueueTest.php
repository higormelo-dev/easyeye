<?php

declare(strict_types=1);

use App\Jobs\NotifyScheduleChangeJob;
use App\Models\{Entity, Patient};
use App\Notifications\ScheduleNotification;
use Illuminate\Contracts\Queue\{ShouldBeEncrypted, ShouldQueue};
use Illuminate\Support\Facades\{DB, Mail, Queue};

/**
 * F1: o e-mail de mudança na agenda gerava 2 jobs (NotifyScheduleChangeJob + a ScheduleNotification `ShouldQueue`, que
 * levava Schedule e paciente inteiros no payload do Redis). Agora a notificação é enviada DENTRO do job, que já é a
 * unidade durável (tries/backoff).
 */
beforeEach(function () {
    $this->entity  = Entity::factory()->create(['is_client' => true, 'active' => true]);
    $this->patient = Patient::factory()->create(['entity_id' => $this->entity->id]);
    $this->patient->person->update(['email' => 'paciente.fila@example.test']);

    ['schedule' => $this->schedule] = createScheduleForEntity($this->entity, ['patient_id' => $this->patient->id, 'full_name' => 'PACIENTE FILA']);
});

it('ScheduleNotification não é ShouldQueue', function () {
    expect(new ScheduleNotification($this->schedule, ScheduleNotification::EVENT_CREATED))->not->toBeInstanceOf(ShouldQueue::class);
});

it('o job envia o e-mail na hora e não enfileira um 2º job', function () {
    Queue::fake();

    (new NotifyScheduleChangeJob($this->schedule->id, ScheduleNotification::EVENT_CREATED))->handle();

    Queue::assertNothingPushed();

    $sent = collect(Mail::getSymfonyTransport()->messages())
        ->map(fn ($message) => $message->getOriginalMessage()->getTo()[0]->getAddress());

    expect($sent->all())->toBe(['paciente.fila@example.test']);
});

it('a repetição por falha de SMTP é do job: 3 tentativas com intervalo de 60 s, na fila default', function () {
    $job = new NotifyScheduleChangeJob($this->schedule->id, ScheduleNotification::EVENT_CREATED);

    expect($job->tries)->toBe(3)
        ->and($job->backoff)->toBe(60)
        ->and($job->queue)->toBe('default');
});

it('paciente sem e-mail válido não gera envio nem job', function () {
    Queue::fake();
    $this->patient->person->update(['email' => 'nao-e-um-email']);

    (new NotifyScheduleChangeJob($this->schedule->id, ScheduleNotification::EVENT_CREATED))->handle();

    Queue::assertNothingPushed();
    expect(Mail::getSymfonyTransport()->messages())->toHaveCount(0);
});

it('o job cifra o payload: o motivo de cancelamento (texto livre) não fica em claro na fila nem em failed_jobs', function () {
    $job = new NotifyScheduleChangeJob($this->schedule->id, ScheduleNotification::EVENT_CANCELLED, 'motivo-sentinela: paciente PACIENTE FILA desistiu');

    expect($job)->toBeInstanceOf(ShouldBeEncrypted::class);

    // Fila `database` de verdade (a tabela `jobs` existe no banco de teste): o payload gravado é o que o Redis
    // e a tabela failed_jobs guardariam.
    dispatch($job->onConnection('database'));

    $payload = (string) DB::table('jobs')->value('payload');

    expect($payload)->not->toBe('')
        ->and($payload)->not->toContain('motivo-sentinela')
        ->and($payload)->not->toContain('PACIENTE FILA')
        ->and(json_decode($payload, true)['data']['command'])->not->toContain('motivo-sentinela');
});
