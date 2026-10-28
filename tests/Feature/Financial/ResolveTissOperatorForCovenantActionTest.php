<?php

declare(strict_types=1);

use App\Domains\Tiss\Actions\ResolveTissOperatorForCovenantAction;
use App\Domains\Tiss\Models\{TissEntityOperatorCredential, TissOperator};
use App\Models\{Covenant, Entity};
use Illuminate\Validation\ValidationException;

it('throws a validation exception when covenant has no ans registry (particular billing)', function (): void {
    $entity   = Entity::factory()->create();
    $covenant = Covenant::factory()->create(['entity_id' => $entity->id, 'ans_registry' => null]);

    $action = app(ResolveTissOperatorForCovenantAction::class);

    expect($action->isEligible($covenant))->toBeFalse();

    expect(fn () => $action($covenant, (string) $entity->id))
        ->toThrow(ValidationException::class);

    expect(TissOperator::query()->count())->toBe(0);
});

it('creates operator, contract and a placeholder credential on first use', function (): void {
    $entity   = Entity::factory()->create();
    $covenant = Covenant::factory()->create([
        'entity_id'    => $entity->id,
        'ans_registry' => '326305',
        'name'         => 'AMIL',
    ]);

    $action   = app(ResolveTissOperatorForCovenantAction::class);
    $contract = $action($covenant, (string) $entity->id);

    expect($contract->contract_code)->toBe('DEFAULT')
        ->and($contract->operator->ans_code)->toBe('326305')
        ->and($contract->requires_authorization)->toBeFalse();

    $covenant->refresh();
    expect($covenant->tiss_operator_id)->toBe($contract->operator_id);

    $credential = TissEntityOperatorCredential::query()
        ->where('entity_id', $entity->id)
        ->where('operator_id', $contract->operator_id)
        ->first();

    expect($credential)->not->toBeNull()
        ->and($credential->active)->toBeTrue()
        ->and($credential->username)->toBeNull();

    expect(TissOperator::query()->count())->toBe(1);
});

it('reuses the already linked operator without creating a duplicate', function (): void {
    $entity   = Entity::factory()->create();
    $covenant = Covenant::factory()->create(['entity_id' => $entity->id, 'ans_registry' => '359017']);

    $action = app(ResolveTissOperatorForCovenantAction::class);
    $first  = $action($covenant, (string) $entity->id);

    $second = $action($covenant->fresh(), (string) $entity->id);

    expect($second->operator_id)->toBe($first->operator_id)
        ->and($second->id)->toBe($first->id)
        ->and(TissOperator::query()->count())->toBe(1)
        ->and(TissEntityOperatorCredential::query()->count())->toBe(1);
});

it('normalizes punctuation in ans registry so two covenants of the same operator share it', function (): void {
    $entity    = Entity::factory()->create();
    $covenantA = Covenant::factory()->create(['entity_id' => $entity->id, 'ans_registry' => '005711']);
    $covenantB = Covenant::factory()->create(['entity_id' => $entity->id, 'ans_registry' => '005.711']);

    $action    = app(ResolveTissOperatorForCovenantAction::class);
    $contractA = $action($covenantA, (string) $entity->id);
    $contractB = $action($covenantB, (string) $entity->id);

    expect($contractA->operator_id)->toBe($contractB->operator_id)
        ->and(TissOperator::query()->count())->toBe(1);
});
