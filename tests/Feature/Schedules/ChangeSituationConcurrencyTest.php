<?php

declare(strict_types=1);

/**
 * ScheduleService::changeSituation sob concorrência.
 *
 * Antes: sem transação e decidindo pela situação da instância em memória.
 * Dois cliques simultâneos ("Chegou" 2x, duas abas) gravavam os dois — 2 logs,
 * arrived_at resetado —, o "de" do histórico saía errado com instância
 * desatualizada e uma falha no INSERT do log deixava a situação mudada sem
 * trilha. Agora: linha travada e relida dentro de uma transação única.
 */

use App\Enums\{ClientRule, ScheduleSituation};
use App\Models\{Entity, Schedule, ScheduleSituationLog, User};
use App\Services\ScheduleService;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/** Duas "abas" com a mesma consulta carregada ANTES de qualquer mudança. */
function cscTabs(Schedule $schedule): array
{
    return [Schedule::query()->findOrFail($schedule->id), Schedule::query()->findOrFail($schedule->id)];
}

beforeEach(function () {
    $this->entity     = Entity::factory()->create(['is_client' => true, 'active' => true]);
    $this->entityUser = createEntityUser($this->entity, User::factory()->create(), ClientRule::Admin->value);
    session(['selected_entity_id' => $this->entity->id]);

    ['schedule' => $this->schedule] = createScheduleForEntity($this->entity);

    $this->service = app(ScheduleService::class);
    $this->logs    = fn () => ScheduleSituationLog::query()->where('schedule_id', $this->schedule->id)->orderBy('created_at')->get();
});

it('duplo "Chegou" com instâncias desatualizadas: o segundo vira no-op (1 log, chegada preservada)', function () {
    [$tabA, $tabB] = cscTabs($this->schedule);

    Carbon::setTestNow('2026-09-27 09:00:00');
    expect($this->service->changeSituation($tabA, ScheduleSituation::Waiting, $this->entityUser->id))->toBeTrue();

    Carbon::setTestNow('2026-09-27 09:05:00');
    expect($this->service->changeSituation($tabB, ScheduleSituation::Waiting, $this->entityUser->id))->toBeFalse();
    Carbon::setTestNow();

    expect(($this->logs)())->toHaveCount(1)
        ->and($this->schedule->fresh()->arrived_at->format('H:i'))->toBe('09:00')
        // A instância da aba B passa a refletir o banco.
        ->and($tabB->situation)->toBe(ScheduleSituation::Waiting);
});

it('o "de" do histórico é a situação do BANCO, não a da instância desatualizada', function () {
    [$tabA, $tabB] = cscTabs($this->schedule);

    $this->service->changeSituation($tabA, ScheduleSituation::Waiting, $this->entityUser->id);
    $this->service->changeSituation($tabB, ScheduleSituation::InProgress, $this->entityUser->id);

    $last = ($this->logs)()->last();

    expect($last->from_situation)->toBe(ScheduleSituation::Waiting)
        ->and($last->to_situation)->toBe(ScheduleSituation::InProgress);
});

it('falha ao gravar o histórico desfaz a mudança de situação (nada sem trilha)', function () {
    ScheduleSituationLog::creating(function (): void {
        throw new RuntimeException('falha simulada no INSERT do histórico');
    });

    expect(fn () => $this->service->changeSituation($this->schedule, ScheduleSituation::Waiting, $this->entityUser->id))
        ->toThrow(RuntimeException::class);

    expect($this->schedule->fresh()->situation)->toBe(ScheduleSituation::Scheduled)
        ->and(($this->logs)())->toHaveCount(0);
});

it('trava e relê a linha ANTES de decidir, e grava update + histórico dentro da mesma transação', function () {
    $log = [];
    DB::listen(function (QueryExecuted $query) use (&$log): void {
        $log[] = ['sql' => strtolower($query->sql), 'level' => $query->connection->transactionLevel()];
    });

    $this->service->changeSituation($this->schedule, ScheduleSituation::Waiting, $this->entityUser->id);

    $entries  = collect($log);
    $lockAt   = $entries->search(fn (array $e): bool => str_contains($e['sql'], 'from "schedules"') && str_contains($e['sql'], 'for update'));
    $updateAt = $entries->search(fn (array $e): bool => str_starts_with($e['sql'], 'update "schedules"'));
    $logAt    = $entries->search(fn (array $e): bool => str_starts_with($e['sql'], 'insert into "schedule_situation_logs"'));

    expect($lockAt)->toBeInt()
        ->and($updateAt)->toBeInt()
        ->and($logAt)->toBeInt()
        ->and($lockAt)->toBeLessThan($updateAt)
        ->and($updateAt)->toBeLessThan($logAt)
        // Lock, update e histórico no mesmo nível de transação (uma só, aninhada na do teste).
        ->and($entries[$lockAt]['level'])->toBe($entries[$logAt]['level'])
        ->and($entries[$updateAt]['level'])->toBe($entries[$logAt]['level'])
        ->and($entries[$lockAt]['level'])->toBeGreaterThan(1);
});

it('alterações ainda não salvas do chamador continuam sendo gravadas junto', function () {
    $this->schedule->notes = 'Observação da recepção';

    $this->service->changeSituation($this->schedule, ScheduleSituation::Confirmed, $this->entityUser->id);

    $fresh = $this->schedule->fresh();

    expect($fresh->situation)->toBe(ScheduleSituation::Confirmed)
        ->and($fresh->notes)->toBe('Observação da recepção')
        ->and($fresh->confirmed_at)->not->toBeNull();
});
