<?php

use App\Enums\{BillingBatchStatus, BillingClaimStatus};
use App\Models\{BillingBatch, BillingClaim, Covenant, Entity};
use App\Services\Financial\BillingService;
use Illuminate\Database\QueryException;

beforeEach(function () {
    $this->entity   = Entity::factory()->create(['is_client' => true, 'active' => true]);
    $this->covenant = Covenant::factory()->create(['entity_id' => $this->entity->id, 'active' => true]);
});

it('índice único parcial barra 2 guias ativas pro mesmo agendamento mesmo pulando o service', function () {
    $schedule = createBillableSchedule($this->entity);

    BillingClaim::query()->create([
        'entity_id'       => $this->entity->id,
        'schedule_id'     => $schedule->id,
        'covenant_id'     => $this->covenant->id,
        'status'          => BillingClaimStatus::Draft->value,
        'attendance_date' => now()->toDateString(),
        'amount'          => 150.00,
        'quantity'        => 1,
        'unit_price'      => 150.00,
    ]);

    expect(fn () => BillingClaim::query()->create([
        'entity_id'       => $this->entity->id,
        'schedule_id'     => $schedule->id,
        'covenant_id'     => $this->covenant->id,
        'status'          => BillingClaimStatus::Draft->value,
        'attendance_date' => now()->toDateString(),
        'amount'          => 150.00,
        'quantity'        => 1,
        'unit_price'      => 150.00,
    ]))->toThrow(QueryException::class);
});

it('não bloqueia refaturar um agendamento cuja guia anterior foi cancelada', function () {
    $schedule = createBillableSchedule($this->entity);

    $cancelled = BillingClaim::query()->create([
        'entity_id'       => $this->entity->id,
        'schedule_id'     => $schedule->id,
        'covenant_id'     => $this->covenant->id,
        'status'          => BillingClaimStatus::Cancelled->value,
        'attendance_date' => now()->toDateString(),
        'amount'          => 150.00,
        'quantity'        => 1,
        'unit_price'      => 150.00,
    ]);

    expect($cancelled->exists)->toBeTrue();

    $active = BillingClaim::query()->create([
        'entity_id'       => $this->entity->id,
        'schedule_id'     => $schedule->id,
        'covenant_id'     => $this->covenant->id,
        'status'          => BillingClaimStatus::Draft->value,
        'attendance_date' => now()->toDateString(),
        'amount'          => 150.00,
        'quantity'        => 1,
        'unit_price'      => 150.00,
    ]);

    expect($active->exists)->toBeTrue();
});

it('submitBatch é idempotente: segunda chamada não reprocessa nem lança erro', function () {
    $batch = BillingBatch::query()->create([
        'entity_id'    => $this->entity->id,
        'covenant_id'  => $this->covenant->id,
        'status'       => BillingBatchStatus::Draft->value,
        'period_start' => now()->startOfMonth()->toDateString(),
        'period_end'   => now()->toDateString(),
        'issued_at'    => now(),
    ]);

    $schedule = createBillableSchedule($this->entity);
    BillingClaim::query()->create([
        'entity_id'       => $this->entity->id,
        'batch_id'        => $batch->id,
        'schedule_id'     => $schedule->id,
        'covenant_id'     => $this->covenant->id,
        'status'          => BillingClaimStatus::Draft->value,
        'attendance_date' => now()->toDateString(),
        'amount'          => 150.00,
        'quantity'        => 1,
        'unit_price'      => 150.00,
    ]);

    $service = app(BillingService::class);

    $first  = $service->submitBatch($batch->fresh());
    $second = $service->submitBatch($first->fresh());

    expect($first->status)->toBe(BillingBatchStatus::Submitted)
        ->and($second->status)->toBe(BillingBatchStatus::Submitted)
        ->and($second->submitted_at->equalTo($first->submitted_at))->toBeTrue();
});
