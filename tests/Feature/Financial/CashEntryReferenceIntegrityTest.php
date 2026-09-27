<?php

declare(strict_types=1);

/*
 * Integridade da referência de sistema do lançamento de caixa
 * (financial_cash_entries.reference_type / reference_id).
 *
 * A referência é o vínculo "este lançamento É o recebimento do agendamento X /
 * da guia Y / do pedido de compra Z" e é lida pela agenda (has_cash_entry),
 * pela trava de duplicidade do recebimento e pela regra "Atendido exige caixa".
 * Antes o CashEntryRequest aceitava esses campos do cliente (string livre + uuid
 * sem escopo): dava para marcar agendamento como pago, bloquear o recebimento
 * legítimo e apontar para agendamento de OUTRA clínica.
 */

use App\Enums\{BillingClaimStatus, CashEntryReferenceType, ClientRule, FinancialEntryStatus, FinancialEntryType, PaymentMethod, ScheduleSituation};
use App\Exceptions\Financial\DuplicateCashEntryException;
use App\Models\{BillingClaim, Covenant, Entity, FinancialCashEntry, PurchaseOrder, Schedule, User};
use App\Services\Financial\{BillingService, CashFlowService};
use App\Services\ScheduleService;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->entity     = Entity::factory()->create(['is_client' => true, 'active' => true]);
    $this->user       = User::factory()->create();
    $this->entityUser = createEntityUser($this->entity, $this->user, ClientRule::Admin->value, isOwner: true);

    ['schedule' => $this->schedule] = createScheduleForEntity($this->entity, [
        'situation' => ScheduleSituation::Waiting->value,
    ]);

    // Outra clínica (tenant B) com o próprio agendamento.
    $this->otherEntity     = Entity::factory()->create(['is_client' => true, 'active' => true, 'requires_cash_to_complete' => true]);
    $this->otherUser       = User::factory()->create();
    $this->otherEntityUser = createEntityUser($this->otherEntity, $this->otherUser, ClientRule::Admin->value, isOwner: true);

    ['schedule' => $this->otherSchedule] = createScheduleForEntity($this->otherEntity, [
        'situation' => ScheduleSituation::Waiting->value,
    ]);
});

/** Payload do modal de Fluxo de Caixa (mesmos campos de CashEntryFormModal.vue). */
function cashRefModalPayload(array $extra = []): array
{
    return array_merge([
        'entry_date'  => now()->toDateString(),
        'description' => 'Consulta particular',
        'type'        => FinancialEntryType::Income->value,
        'status'      => FinancialEntryStatus::Paid->value,
        'amount'      => 200,
    ], $extra);
}

/** Lançamento manual de caixa gravado direto (sem passar pela request). */
function cashRefManualEntry(Entity $entity, array $extra = []): FinancialCashEntry
{
    return FinancialCashEntry::query()->create(array_merge([
        'entity_id'   => $entity->id,
        'entry_date'  => now()->toDateString(),
        'description' => 'Lançamento avulso',
        'type'        => FinancialEntryType::Income->value,
        'status'      => FinancialEntryStatus::Paid->value,
        'amount'      => 90,
        'active'      => true,
    ], $extra));
}

/**
 * Linha "forjada" gravada pela falha antiga: lançamento da clínica A que aponta
 * para o agendamento de outra clínica.
 */
function cashRefForgedCrossTenantEntry($test): FinancialCashEntry
{
    return cashRefManualEntry($test->entity, [
        'reference_type' => CashEntryReferenceType::Schedule->value,
        'reference_id'   => $test->otherSchedule->id,
    ]);
}

function cashRefActingAsA($test)
{
    return $test->actingAs($test->user)->withSession(panelSession($test->entityUser));
}

function cashRefActingAsB($test)
{
    return $test->actingAs($test->otherUser)->withSession(panelSession($test->otherEntityUser));
}

describe('CashEntryRequest: referência de sistema não vem do cliente', function () {
    it('recusa criar lançamento com referência forjada para agendamento da própria clínica', function () {
        cashRefActingAsA($this)
            ->postJson(route('panel.financial.cash-flow.store'), cashRefModalPayload([
                'reference_type' => 'schedule',
                'reference_id'   => $this->schedule->id,
            ]))
            ->assertStatus(422)
            ->assertJsonPath('errors.reference_type.0', __('financial_cash_flow.reference_managed_by_system'))
            ->assertJsonPath('errors.reference_id.0', __('financial_cash_flow.reference_managed_by_system'));

        expect(FinancialCashEntry::query()->withoutEntityScope()->count())->toBe(0)
            ->and($this->schedule->financialEntries()->exists())->toBeFalse();
    });

    it('recusa referência apontando para agendamento de outra clínica', function () {
        cashRefActingAsA($this)
            ->postJson(route('panel.financial.cash-flow.store'), cashRefModalPayload([
                'reference_type' => 'schedule',
                'reference_id'   => $this->otherSchedule->id,
            ]))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['reference_type', 'reference_id']);

        expect(FinancialCashEntry::query()->withoutEntityScope()->count())->toBe(0);
    });

    it('recusa reference_type com nome de classe arbitrária', function () {
        cashRefActingAsA($this)
            ->postJson(route('panel.financial.cash-flow.store'), cashRefModalPayload([
                'reference_type' => User::class,
                'reference_id'   => $this->user->id,
            ]))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['reference_type', 'reference_id']);

        expect(FinancialCashEntry::query()->withoutEntityScope()->count())->toBe(0);
    });

    it('recusa PATCH que tenta vincular um lançamento avulso a um agendamento', function () {
        $entry = cashRefManualEntry($this->entity);

        cashRefActingAsA($this)
            ->patchJson(route('panel.financial.cash-flow.update', $entry->id), cashRefModalPayload([
                'reference_type' => 'schedule',
                'reference_id'   => $this->schedule->id,
            ]))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['reference_type', 'reference_id']);

        $fresh = $entry->fresh();
        expect($fresh->reference_type)->toBeNull()
            ->and($fresh->reference_id)->toBeNull()
            ->and($this->schedule->financialEntries()->exists())->toBeFalse();
    });

    it('recusa PATCH que tenta desvincular (null) o recebimento do agendamento', function () {
        $entry = cashRefManualEntry($this->entity, [
            'reference_type' => CashEntryReferenceType::Schedule->value,
            'reference_id'   => $this->schedule->id,
        ]);

        cashRefActingAsA($this)
            ->patchJson(route('panel.financial.cash-flow.update', $entry->id), cashRefModalPayload([
                'reference_type' => null,
                'reference_id'   => null,
            ]))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['reference_type', 'reference_id']);

        $fresh = $entry->fresh();
        expect($fresh->reference_type)->toBe('schedule')
            ->and($fresh->reference_id)->toBe($this->schedule->id);
    });

    it('editar o recebimento do agendamento pelo modal preserva a referência de sistema', function () {
        $entry = cashRefManualEntry($this->entity, [
            'reference_type' => CashEntryReferenceType::Schedule->value,
            'reference_id'   => $this->schedule->id,
        ]);

        cashRefActingAsA($this)
            ->patchJson(route('panel.financial.cash-flow.update', $entry->id), cashRefModalPayload([
                'description' => 'Consulta — ajuste',
                'status'      => FinancialEntryStatus::Pending->value,
            ]))
            ->assertOk();

        $fresh = $entry->fresh();
        expect($fresh->description)->toBe('Consulta — ajuste')
            ->and($fresh->reference_type)->toBe('schedule')
            ->and($fresh->reference_id)->toBe($this->schedule->id);
    });

    it('o service também ignora referência/guia/tenant num update vindo de outro chamador', function () {
        $entry = cashRefManualEntry($this->entity);

        app(CashFlowService::class)->update($entry, [
            'description'      => 'Alterado',
            'reference_type'   => 'schedule',
            'reference_id'     => $this->schedule->id,
            'entity_id'        => $this->otherEntity->id,
            'billing_claim_id' => null,
        ]);

        $fresh = FinancialCashEntry::query()->withoutEntityScope()->find($entry->id);
        expect($fresh->description)->toBe('Alterado')
            ->and($fresh->reference_type)->toBeNull()
            ->and($fresh->reference_id)->toBeNull()
            ->and($fresh->entity_id)->toBe($this->entity->id);
    });
});

describe('CashEntryRequest: demais campos interpretados pelo sistema', function () {
    it('recusa payment_method fora do enum PaymentMethod', function () {
        cashRefActingAsA($this)
            ->postJson(route('panel.financial.cash-flow.store'), cashRefModalPayload([
                'payment_method' => 'bitcoin',
            ]))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['payment_method']);

        expect(FinancialCashEntry::query()->withoutEntityScope()->count())->toBe(0);
    });

    it('normaliza payment_method (caixa/espaços) e grava o valor do enum', function () {
        cashRefActingAsA($this)
            ->postJson(route('panel.financial.cash-flow.store'), cashRefModalPayload([
                'payment_method' => '  CASH ',
            ]))
            ->assertOk();

        expect(FinancialCashEntry::query()->withoutEntityScope()->sole()->payment_method)->toBe(PaymentMethod::Cash);
    });

    it('recusa valor acima da precisão da coluna (antes: 500 numeric overflow)', function () {
        cashRefActingAsA($this)
            ->postJson(route('panel.financial.cash-flow.store'), cashRefModalPayload([
                'amount' => 10000000000,
            ]))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['amount']);
    });

    it('ignora billing_claim_id, nature e entity_id enviados pelo cliente', function () {
        $claim = BillingClaim::create([
            'entity_id'       => $this->entity->id,
            'covenant_id'     => Covenant::factory()->create(['entity_id' => $this->entity->id])->id,
            'status'          => BillingClaimStatus::Submitted->value,
            'attendance_date' => now()->toDateString(),
            'amount'          => 100,
            'quantity'        => 1,
            'unit_price'      => 100,
        ]);

        cashRefActingAsA($this)
            ->postJson(route('panel.financial.cash-flow.store'), cashRefModalPayload([
                'billing_claim_id' => $claim->id,
                'nature'           => 'covenant',
                'entity_id'        => $this->otherEntity->id,
            ]))
            ->assertOk();

        $entry = FinancialCashEntry::query()->withoutEntityScope()->sole();
        expect($entry->billing_claim_id)->toBeNull()
            ->and($entry->nature->value)->toBe('general')
            ->and($entry->entity_id)->toBe($this->entity->id);
    });
});

describe('model: só tipos de referência da whitelist são persistidos', function () {
    it('bloqueia gravar reference_type fora da whitelist', function () {
        expect(fn () => cashRefManualEntry($this->entity, [
            'reference_type' => User::class,
            'reference_id'   => $this->user->id,
        ]))->toThrow(InvalidArgumentException::class);

        expect(FinancialCashEntry::query()->withoutEntityScope()->count())->toBe(0);
    });

    it('aceita os tipos gravados pelos fluxos de sistema', function (string $type) {
        $entry = cashRefManualEntry($this->entity, [
            'reference_type' => $type,
            'reference_id'   => (string) Str::uuid(),
        ]);

        expect($entry->exists)->toBeTrue();
    })->with([
        'agendamento'      => 'schedule',
        'guia'             => 'billing_claim',
        'pedido de compra' => PurchaseOrder::class,
    ]);

    it('não expõe mais morphTo sobre a string livre reference_type', function () {
        expect(method_exists(FinancialCashEntry::class, 'referenceable'))->toBeFalse();
    });
});

describe('leituras da referência escopadas pela clínica do agendamento', function () {
    it('lançamento de outra clínica não conta como caixa do agendamento (relation)', function () {
        cashRefForgedCrossTenantEntry($this);

        expect($this->otherSchedule->financialEntries()->exists())->toBeFalse()
            ->and(Schedule::query()->withoutGlobalScopes()->with('financialEntries')->find($this->otherSchedule->id)->financialEntries)->toHaveCount(0);
    });

    it('lançamento forjado de outra clínica não bloqueia o recebimento legítimo (service)', function () {
        cashRefForgedCrossTenantEntry($this);

        session(['selected_entity_id' => $this->otherEntity->id]);

        $entry = app(CashFlowService::class)->createForSchedule($this->otherSchedule, [
            'entry_date'     => now()->toDateString(),
            'description'    => 'Consulta',
            'payment_method' => PaymentMethod::Cash->value,
            'amount'         => 150,
        ]);

        expect($entry->entity_id)->toBe($this->otherEntity->id)
            ->and($entry->reference_id)->toBe($this->otherSchedule->id);
    });

    it('lançamento forjado de outra clínica não libera "Atendido" quando a clínica exige caixa', function () {
        cashRefForgedCrossTenantEntry($this);

        expect(app(ScheduleService::class)->attendedBlockedByCash($this->otherSchedule))->toBeTrue();
    });

    it('agenda da outra clínica não mostra o agendamento como pago e aceita o recebimento (HTTP)', function () {
        cashRefForgedCrossTenantEntry($this);

        cashRefActingAsB($this)
            ->get(route('panel.schedules.index', ['date' => $this->otherSchedule->date_time->toDateString()]))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('scheduleItems.0.id', $this->otherSchedule->id)
                ->where('scheduleItems.0.has_cash_entry', false));

        cashRefActingAsB($this)
            ->postJson(route('panel.schedules.cash-entry.store', $this->otherSchedule), [
                'entry_date'     => now()->toDateString(),
                'description'    => 'Consulta',
                'payment_method' => PaymentMethod::Cash->value,
                'amount'         => 150,
            ])
            ->assertOk();

        cashRefActingAsB($this)
            ->get(route('panel.schedules.index', ['date' => $this->otherSchedule->date_time->toDateString()]))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('scheduleItems.0.has_cash_entry', true));
    });
});

describe('fluxos de sistema preservados', function () {
    it('recebimento na chegada continua vinculando, marcando pago e barrando duplicidade', function () {
        $payload = [
            'entry_date'     => now()->toDateString(),
            'description'    => 'Consulta',
            'payment_method' => PaymentMethod::Cash->value,
            'amount'         => 150,
        ];

        cashRefActingAsA($this)->postJson(route('panel.schedules.cash-entry.store', $this->schedule), $payload)->assertOk();

        $entry = FinancialCashEntry::query()->withoutEntityScope()->sole();
        expect($entry->reference_type)->toBe(CashEntryReferenceType::Schedule->value)
            ->and($entry->reference_id)->toBe($this->schedule->id)
            ->and($entry->entity_id)->toBe($this->entity->id)
            ->and($this->schedule->financialEntries()->exists())->toBeTrue();

        cashRefActingAsA($this)
            ->get(route('panel.schedules.index', ['date' => $this->schedule->date_time->toDateString()]))
            ->assertInertia(fn ($page) => $page->where('scheduleItems.0.has_cash_entry', true));

        cashRefActingAsA($this)->postJson(route('panel.schedules.cash-entry.store', $this->schedule), $payload)->assertStatus(422);

        session(['selected_entity_id' => $this->entity->id]);
        expect(fn () => app(CashFlowService::class)->createForSchedule($this->schedule, $payload))
            ->toThrow(DuplicateCashEntryException::class);
    });

    it('pagamento de guia continua criando a entrada com a referência billing_claim', function () {
        $claim = BillingClaim::create([
            'entity_id'       => $this->entity->id,
            'covenant_id'     => Covenant::factory()->create(['entity_id' => $this->entity->id])->id,
            'status'          => BillingClaimStatus::Submitted->value,
            'attendance_date' => now()->toDateString(),
            'amount'          => 300,
            'quantity'        => 1,
            'unit_price'      => 300,
        ]);

        app(BillingService::class)->markClaimPaid($claim);

        $entry = FinancialCashEntry::query()->withoutEntityScope()->where('billing_claim_id', $claim->id)->sole();
        expect($entry->reference_type)->toBe(CashEntryReferenceType::BillingClaim->value)
            ->and($entry->reference_id)->toBe($claim->id)
            ->and($entry->entity_id)->toBe($this->entity->id);
    });
});
