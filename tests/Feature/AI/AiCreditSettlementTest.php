<?php

use App\Domains\AI\Models\{AiCreditLedgerEntry, AiCreditWallet, AiRun};
use App\Domains\AI\Services\AiCreditWalletService;
use App\Enums\AI\{AiLedgerEntryType, AiRunStatus};
use App\Models\Entity;
use Carbon\CarbonImmutable;

/*
 * Liquidação da reserva pela origem (cota × saldo comprado) — bugs B4/COTA-3
 * da auditoria: a sobra de uma execução paga com crédito comprado voltava à
 * cota (que expira) e deixava "Reservados" fantasma; o consumo de uma
 * execução zerava o reservado de outras.
 */

beforeEach(function () {
    $this->entity  = Entity::factory()->create(['is_client' => true]);
    $this->service = app(AiCreditWalletService::class);
});

function settlementRun(Entity $entity, AiRunStatus $status = AiRunStatus::Reserved): AiRun
{
    return AiRun::factory()->create(['entity_id' => $entity->id, 'status' => $status->value]);
}

function settlementWallet(Entity $entity): AiCreditWallet
{
    return AiCreditWallet::query()->where('entity_id', $entity->id)->firstOrFail();
}

test('sobra de reserva paga com crédito comprado volta ao comprado, não à cota que expira', function () {
    // Franquia 30/30 usada + 100 comprados: a reserva sai toda do comprado.
    $this->service->grantMonthlyQuota($this->entity->id, 30, CarbonImmutable::now()->addMonth());
    $this->service->purchaseCredits($this->entity->id, 100);
    settlementWallet($this->entity)->update(['monthly_quota_used' => 30]);

    $run = settlementRun($this->entity);
    $this->service->reserve($this->entity->id, 4, aiRunId: $run->id);

    // Execução falhou: devolve a reserva inteira.
    $release = $this->service->releaseReservation($this->entity->id, 4, aiRunId: $run->id);

    expect($release->metadata['to_balance'])->toBe(4)
        ->and($release->metadata['to_quota'])->toBe(0);

    $wallet = settlementWallet($this->entity);
    expect($wallet->balance)->toBe(100)
        ->and($wallet->monthly_quota_used)->toBe(30)
        ->and($wallet->reserved_balance)->toBe(0);
});

test('consumo sai primeiro da cota e a sobra devolve o comprado', function () {
    // 40 livres na cota + 100 comprados; reserva 60 = 40 da cota + 20 do comprado.
    $this->service->grantMonthlyQuota($this->entity->id, 40, CarbonImmutable::now()->addMonth());
    $this->service->purchaseCredits($this->entity->id, 100);

    $run = settlementRun($this->entity);
    $this->service->reserve($this->entity->id, 60, aiRunId: $run->id);

    $consume = $this->service->consumeReservation($this->entity->id, 10, aiRunId: $run->id);
    $release = $this->service->releaseReservation($this->entity->id, 50, aiRunId: $run->id);

    expect($consume->metadata['from_quota'])->toBe(10)
        ->and($consume->metadata['from_balance'])->toBe(0)
        ->and($release->metadata['to_balance'])->toBe(20)
        ->and($release->metadata['to_quota'])->toBe(30);

    $wallet = settlementWallet($this->entity);
    expect($wallet->monthly_quota_used)->toBe(10)   // só o consumido saiu da cota
        ->and($wallet->balance)->toBe(100)              // comprado intacto
        ->and($wallet->reserved_balance)->toBe(0);
});

test('consumo de uma execução não mexe no reservado de outra', function () {
    $this->service->grantMonthlyQuota($this->entity->id, 5, CarbonImmutable::now()->addMonth());
    $this->service->purchaseCredits($this->entity->id, 50);

    $fromQuota   = settlementRun($this->entity);
    $fromBalance = settlementRun($this->entity);

    $this->service->reserve($this->entity->id, 5, aiRunId: $fromQuota->id);    // 5 da cota
    $this->service->reserve($this->entity->id, 10, aiRunId: $fromBalance->id); // 10 do comprado

    expect(settlementWallet($this->entity)->reserved_balance)->toBe(10);

    // A execução paga com cota consome tudo: o reservado (do comprado) da outra fica.
    $this->service->consumeReservation($this->entity->id, 5, aiRunId: $fromQuota->id);

    expect(settlementWallet($this->entity)->reserved_balance)->toBe(10);

    $this->service->consumeReservation($this->entity->id, 10, aiRunId: $fromBalance->id);

    $wallet = settlementWallet($this->entity);
    expect($wallet->reserved_balance)->toBe(0)
        ->and($wallet->balance)->toBe(40)
        ->and($wallet->monthly_quota_used)->toBe(5);
});

test('virada da janela durante a execução: a parte da cota se perde sem quebrar a liberação', function () {
    $this->service->grantMonthlyQuota($this->entity->id, 20, CarbonImmutable::now()->addDay());
    $this->service->purchaseCredits($this->entity->id, 10);

    $run = settlementRun($this->entity);
    $this->service->reserve($this->entity->id, 25, aiRunId: $run->id); // 20 da cota + 5 do comprado

    // Nova janela concedida enquanto a execução rodava.
    $this->service->grantMonthlyQuota($this->entity->id, 20, CarbonImmutable::now()->addMonth(), idempotencyKey: 'next-window');

    $release = $this->service->releaseReservation($this->entity->id, 25, aiRunId: $run->id);

    expect($release->metadata['to_balance'])->toBe(5)
        ->and($release->metadata['to_quota'])->toBe(0)
        ->and($release->metadata['quota_forfeited'])->toBe(20);

    $wallet = settlementWallet($this->entity);
    expect($wallet->monthly_quota_used)->toBe(0)   // a janela nova não ganha nem perde
        ->and($wallet->balance)->toBe(10)
        ->and($wallet->reserved_balance)->toBe(0);
});

test('liberação maior que o reservado do run é recusada', function () {
    $this->service->purchaseCredits($this->entity->id, 10);

    $run = settlementRun($this->entity);
    $this->service->reserve($this->entity->id, 4, aiRunId: $run->id);

    expect(fn () => $this->service->releaseReservation($this->entity->id, 5, aiRunId: $run->id))
        ->toThrow(InvalidArgumentException::class);
});

test('saneamento devolve ao comprado o reservado sem execução em andamento', function () {
    $this->service->purchaseCredits($this->entity->id, 100);

    // Execução em andamento com 4 do comprado reservados (legítimo).
    $running = settlementRun($this->entity, AiRunStatus::Running);
    $this->service->reserve($this->entity->id, 4, aiRunId: $running->id);

    // "Reservados" fantasma deixado por liquidações antigas.
    settlementWallet($this->entity)->increment('reserved_balance', 6);

    $dryRun = $this->service->reconcileReservedBalance($this->entity->id);

    expect($dryRun['reserved'])->toBe(10)
        ->and($dryRun['expected'])->toBe(4)
        ->and($dryRun['phantom'])->toBe(6)
        ->and($dryRun['applied'])->toBeFalse()
        ->and(settlementWallet($this->entity)->reserved_balance)->toBe(10);

    $this->artisan('ai:reconcile-reserved-balance', ['--apply' => true])->assertSuccessful();

    $wallet = settlementWallet($this->entity);
    expect($wallet->reserved_balance)->toBe(4)
        ->and($wallet->balance)->toBe(96 + 6);

    $adjustment = AiCreditLedgerEntry::query()
        ->where('entity_id', $this->entity->id)
        ->where('type', AiLedgerEntryType::Adjustment->value)
        ->sole();

    expect($adjustment->amount)->toBe(6)
        ->and($adjustment->metadata['adjustment_reason'])->toBe('reserved_balance_reconcile');

    // Rodar de novo não devolve outra vez.
    expect($this->service->reconcileReservedBalance($this->entity->id, apply: true)['phantom'])->toBe(0);
});
