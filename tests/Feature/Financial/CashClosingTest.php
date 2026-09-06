<?php

use App\Enums\ClientRule;
use App\Exceptions\Financial\CashPeriodClosedException;
use App\Models\{Entity, FinancialCashEntry, User};
use App\Services\Financial\CashClosingService;
use Illuminate\Testing\TestResponse;

beforeEach(function () {
    $this->entity     = Entity::factory()->create(['is_client' => true, 'active' => true]);
    $this->user       = User::factory()->create();
    $this->entityUser = createEntityUser($this->entity, $this->user, ClientRule::Admin->value);
});

/** POST autenticado no fluxo de caixa. */
function postEntry($test, array $payload): TestResponse
{
    return $test->actingAs($test->user)
        ->withSession(panelSession($test->entityUser))
        ->postJson(route('panel.financial.cash-flow.store'), $payload);
}

describe('CashClosingService::closePeriod', function () {
    it('fecha o período calculando os totais', function () {
        postEntry($this, ['entry_date' => '2026-06-10', 'description' => 'a', 'type' => 'income', 'amount' => 100])->assertOk();
        postEntry($this, ['entry_date' => '2026-06-12', 'description' => 'b', 'type' => 'expense', 'amount' => 30])->assertOk();

        $close = app(CashClosingService::class)->closePeriod($this->entity->id, '2026-06-01', '2026-06-30');

        expect((float) $close->total_income)->toBe(100.0)
            ->and((float) $close->total_expense)->toBe(30.0)
            ->and((float) $close->balance)->toBe(70.0);
    });

    it('impede fechamento sobreposto a um período já fechado', function () {
        app(CashClosingService::class)->closePeriod($this->entity->id, '2026-06-01', '2026-06-30');

        expect(fn () => app(CashClosingService::class)->closePeriod($this->entity->id, '2026-06-15', '2026-07-15'))
            ->toThrow(CashPeriodClosedException::class);
    });
});

describe('bloqueio de lançamentos em período fechado', function () {
    it('bloqueia novo lançamento dentro do período fechado', function () {
        app(CashClosingService::class)->closePeriod($this->entity->id, '2026-06-01', '2026-06-30');

        postEntry($this, ['entry_date' => '2026-06-15', 'description' => 'x', 'type' => 'income', 'amount' => 50])
            ->assertStatus(422);

        expect(FinancialCashEntry::where('entity_id', $this->entity->id)->count())->toBe(0);
    });

    it('permite lançamento fora do período fechado', function () {
        app(CashClosingService::class)->closePeriod($this->entity->id, '2026-06-01', '2026-06-30');

        postEntry($this, ['entry_date' => '2026-07-15', 'description' => 'x', 'type' => 'income', 'amount' => 50])
            ->assertOk();
    });

    it('libera o período após reabertura', function () {
        $close = app(CashClosingService::class)->closePeriod($this->entity->id, '2026-06-01', '2026-06-30');
        app(CashClosingService::class)->reopen($close);

        postEntry($this, ['entry_date' => '2026-06-15', 'description' => 'x', 'type' => 'income', 'amount' => 50])
            ->assertOk();
    });

    it('bloqueia exclusão de lançamento dentro do período fechado', function () {
        postEntry($this, ['entry_date' => '2026-06-15', 'description' => 'x', 'type' => 'income', 'amount' => 50])
            ->assertOk();

        $entry = FinancialCashEntry::where('entity_id', $this->entity->id)->firstOrFail();

        app(CashClosingService::class)->closePeriod($this->entity->id, '2026-06-01', '2026-06-30');

        $this->actingAs($this->user)
            ->withSession(panelSession($this->entityUser))
            ->deleteJson(route('panel.financial.cash-flow.destroy', $entry))
            ->assertStatus(422);

        expect(FinancialCashEntry::withTrashed()->find($entry->id)->trashed())->toBeFalse();
    });

    it('permite exclusão de lançamento fora do período fechado', function () {
        postEntry($this, ['entry_date' => '2026-07-15', 'description' => 'x', 'type' => 'income', 'amount' => 50])
            ->assertOk();

        $entry = FinancialCashEntry::where('entity_id', $this->entity->id)->firstOrFail();

        app(CashClosingService::class)->closePeriod($this->entity->id, '2026-06-01', '2026-06-30');

        $this->actingAs($this->user)
            ->withSession(panelSession($this->entityUser))
            ->deleteJson(route('panel.financial.cash-flow.destroy', $entry))
            ->assertOk();

        expect(FinancialCashEntry::withTrashed()->find($entry->id)->trashed())->toBeTrue();
    });
});
