<?php

use App\Broadcasting\ManagerCovenantImportChannel;
use App\Enums\{ClientRule, ImportStatus, SaasRule};
use App\Events\ImportProgressUpdated;
use App\Jobs\ProcessCovenantImportJob;
use App\Models\{CovenantImport, Entity, User};
use App\Services\Covenants\AnsOperatorImportService;
use Illuminate\Console\Scheduling\{Event as ScheduledEvent, Schedule};
use Illuminate\Queue\MaxAttemptsExceededException;
use Illuminate\Support\Facades\{Event, Queue};

/**
 * Sincronização semanal com a ANS: comando + agendamento, desfecho do job
 * quando o worker morre e autorização do canal privado do progresso.
 */
function covenantImport(ImportStatus $status = ImportStatus::Processing, array $attributes = []): CovenantImport
{
    return CovenantImport::query()->create([
        'source'     => CovenantImport::SOURCE_ANS,
        'status'     => $status,
        'phase'      => $status === ImportStatus::Processing ? 'processing' : null,
        'modalities' => ['Medicina de Grupo'],
        ...$attributes,
    ]);
}

function ansSyncScheduledEvent(): ?ScheduledEvent
{
    return collect(app(Schedule::class)->events())
        ->first(fn (ScheduledEvent $e) => str_contains((string) $e->command, 'covenants:sync-ans'));
}

it('comando enfileira a sincronização semanal com as modalidades padrão', function () {
    Queue::fake();

    $this->artisan('covenants:sync-ans')->assertSuccessful();

    $import = CovenantImport::query()->sole();
    expect($import->source)->toBe(CovenantImport::SOURCE_SCHEDULED)
        ->and($import->user_id)->toBeNull()
        ->and($import->status)->toBe(ImportStatus::Pending)
        ->and($import->modalities)->toBe(config('covenants.ans.default_modalities'))
        ->and($import->modalities)->not->toContain('Odontologia de Grupo');
    Queue::assertPushed(ProcessCovenantImportJob::class);
});

it('comando não empilha outra sincronização com uma em andamento', function () {
    Queue::fake();
    covenantImport(ImportStatus::Pending);

    $this->artisan('covenants:sync-ans')->assertSuccessful();

    expect(CovenantImport::query()->count())->toBe(1);
    Queue::assertNothingPushed();
});

it('agendamento: toda segunda às 04:30, sem sobreposição, desligável por config', function () {
    $event = ansSyncScheduledEvent();

    expect($event)->not->toBeNull()
        ->and($event->expression)->toBe('30 4 * * 1')
        ->and($event->withoutOverlapping)->toBeTrue();

    config(['covenants.ans.sync_enabled' => true]);
    expect($event->filtersPass(app()))->toBeTrue();

    config(['covenants.ans.sync_enabled' => false]);
    expect($event->filtersPass(app()))->toBeFalse();
});

it('[FILA] worker morto encerra a sincronização como falha e transmite o estado final', function () {
    Event::fake([ImportProgressUpdated::class]);
    $import = covenantImport();

    (new ProcessCovenantImportJob($import))->failed(new MaxAttemptsExceededException('attempted too many times'));

    $import->refresh();
    expect($import->status)->toBe(ImportStatus::Failed)
        ->and($import->phase)->toBeNull()
        ->and($import->error)->toBe(__('manager_covenants.import_failed_generic'))
        ->and($import->finished_at)->not->toBeNull();

    Event::assertDispatched(ImportProgressUpdated::class, fn (ImportProgressUpdated $e) => $e->payload['status'] === ImportStatus::Failed->value
        && $e->broadcastOn()[0]->name === 'private-manager.imports.covenants.' . $import->id);
});

it('[FILA] desfecho já gravado pelo serviço não é sobrescrito', function () {
    $import = covenantImport(ImportStatus::Done);

    (new ProcessCovenantImportJob($import))->failed(new RuntimeException('x'));

    expect($import->fresh()->status)->toBe(ImportStatus::Done)
        ->and($import->fresh()->error)->toBeNull();
});

describe('autorização do canal manager.imports.covenants.{id}', function () {
    beforeEach(function () {
        $this->saas   = Entity::factory()->create(['is_client' => false, 'active' => true]);
        $this->import = covenantImport();
    });

    function joinCovenantChannel(User $user, Entity $entity, string $id): bool
    {
        session(['selected_entity_id' => $entity->id, 'selected_entity_is_client' => $entity->isClient()]);

        return app(ManagerCovenantImportChannel::class)->join($user, $id);
    }

    it('admin SaaS e dono entram', function () {
        $admin = User::factory()->create();
        createEntityUser($this->saas, $admin, SaasRule::Admin->value);
        $owner = User::factory()->create();
        createEntityUser($this->saas, $owner, SaasRule::Financial->value, isOwner: true);

        expect(joinCovenantChannel($admin, $this->saas, $this->import->id))->toBeTrue()
            ->and(joinCovenantChannel($owner, $this->saas, $this->import->id))->toBeTrue();
    });

    it('[SEGURANÇA] suporte/financeiro SaaS não entram', function () {
        $user = User::factory()->create();
        createEntityUser($this->saas, $user, SaasRule::Support->value);

        expect(joinCovenantChannel($user, $this->saas, $this->import->id))->toBeFalse();
    });

    it('[SEGURANÇA] usuário de clínica, id inválido ou inexistente não entram', function () {
        $clinic = Entity::factory()->create(['is_client' => true, 'active' => true]);
        $user   = User::factory()->create();
        createEntityUser($clinic, $user, ClientRule::Admin->value);

        $admin = User::factory()->create();
        createEntityUser($this->saas, $admin, SaasRule::Admin->value);

        expect(joinCovenantChannel($user, $clinic, $this->import->id))->toBeFalse()
            ->and(joinCovenantChannel($admin, $this->saas, 'nao-e-uuid'))->toBeFalse()
            ->and(joinCovenantChannel($admin, $this->saas, '01a10021-f756-711a-abba-d8acecb8edfe'))->toBeFalse();
    });
});

describe('sincronização parada (worker fora do ar)', function () {
    beforeEach(function () {
        $this->saas  = Entity::factory()->create(['is_client' => false, 'active' => true]);
        $this->admin = User::factory()->create();
        createEntityUser($this->saas, $this->admin, SaasRule::Admin->value);
    });

    function asSyncAdmin(User $user, Entity $saas, string $rule = 'admin')
    {
        return test()->actingAs($user)->withSession([
            'selected_entity_id' => $saas->id, 'selected_entity_is_client' => false, 'selected_entity_user_rule' => $rule,
        ]);
    }

    it('payload informa há quanto tempo está parada e o limite de cada estado', function () {
        $pending = covenantImport(ImportStatus::Pending);
        $pending->forceFill(['created_at' => now()->subSeconds(120)])->saveQuietly();

        expect($pending->fresh()->progressPayload())
            ->toMatchArray(['stall_after_seconds' => CovenantImport::PENDING_STALL_SECONDS])
            ->and($pending->fresh()->progressPayload()['idle_seconds'])->toBeGreaterThanOrEqual(120)
            ->and($pending->fresh()->isStalled())->toBeTrue()
            ->and(covenantImport(ImportStatus::Done)->progressPayload()['stall_after_seconds'])->toBeNull();
    });

    it('na fila há mais de 90 s: admin cancela, a trilha registra o motivo e libera nova sincronização', function () {
        Queue::fake();
        $stuck = covenantImport(ImportStatus::Pending);
        $stuck->forceFill(['created_at' => now()->subMinutes(5)])->saveQuietly();

        asSyncAdmin($this->admin, $this->saas)->post(route('manager.covenants.imports.cancel', $stuck->id))
            ->assertRedirect()->assertSessionHasNoErrors();

        expect($stuck->fresh()->status)->toBe(ImportStatus::Cancelled)
            ->and($stuck->fresh()->error)->toBe(__('manager_covenants.import_cancelled_reason'))
            ->and($stuck->fresh()->finished_at)->not->toBeNull();

        asSyncAdmin($this->admin, $this->saas)->post(route('manager.covenants.imports.store'), ['source' => 'ans', 'modalities' => ['Autogestão']])
            ->assertSessionHasNoErrors();
        Queue::assertPushed(ProcessCovenantImportJob::class);
    });

    it('processando sem progresso além do timeout do job também pode ser cancelada', function () {
        $dead = covenantImport(ImportStatus::Processing);
        $dead->forceFill(['updated_at' => now()->subMinutes(20)])->saveQuietly();

        asSyncAdmin($this->admin, $this->saas)->post(route('manager.covenants.imports.cancel', $dead->id))->assertSessionHasNoErrors();

        expect($dead->fresh()->status)->toBe(ImportStatus::Cancelled);
    });

    it('em andamento normal (recém-criada ou com progresso recente) o cancelamento é recusado', function () {
        $fresh   = covenantImport(ImportStatus::Pending);
        $running = covenantImport(ImportStatus::Processing);

        asSyncAdmin($this->admin, $this->saas)->post(route('manager.covenants.imports.cancel', $fresh->id))
            ->assertSessionHasErrors(['import' => __('manager_covenants.import_not_stalled')]);
        asSyncAdmin($this->admin, $this->saas)->post(route('manager.covenants.imports.cancel', $running->id))
            ->assertSessionHasErrors('import');

        expect($fresh->fresh()->status)->toBe(ImportStatus::Pending)
            ->and($running->fresh()->status)->toBe(ImportStatus::Processing);
    });

    it('[SEGURANÇA] suporte SaaS não cancela', function () {
        $stuck = covenantImport(ImportStatus::Pending);
        $stuck->forceFill(['created_at' => now()->subMinutes(5)])->saveQuietly();
        $support = User::factory()->create();
        createEntityUser($this->saas, $support, SaasRule::Support->value);

        asSyncAdmin($support, $this->saas, SaasRule::Support->value)->post(route('manager.covenants.imports.cancel', $stuck->id))
            ->assertForbidden();

        expect($stuck->fresh()->status)->toBe(ImportStatus::Pending);
    });

    it('[FILA] job de uma sincronização cancelada (worker voltou depois) não roda', function () {
        $cancelled = covenantImport(ImportStatus::Cancelled);

        (new ProcessCovenantImportJob($cancelled))->handle(app(AnsOperatorImportService::class));

        expect($cancelled->fresh()->status)->toBe(ImportStatus::Cancelled)
            ->and($cancelled->fresh()->started_at)->toBeNull();
    });
});
