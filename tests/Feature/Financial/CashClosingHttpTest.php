<?php

/*
 * Fechamento de caixa via HTTP (panel/financial/cash-closing): fim no futuro
 * recusado, filtros inválidos sem 500, prévia com apoio à conferência
 * (sem mudar os totais do snapshot) e histórico com quem fechou.
 */

use App\Enums\{FinancialEntryStatus, FinancialEntryType};
use App\Models\{CashClose, Entity, FinancialCashEntry, User};
use App\Services\Financial\CashClosingService;

beforeEach(function () {
    $this->entity     = Entity::factory()->create(['is_client' => true, 'active' => true]);
    $this->entityUser = actingAsFinancialEntityUser($this->entity);
});

function cashClosingHttpEntry($test, string $date, string $type, float $amount, string $status = 'paid'): FinancialCashEntry
{
    return FinancialCashEntry::query()->create([
        'entity_id'   => $test->entity->id,
        'entry_date'  => $date,
        'description' => "Lançamento {$type}",
        'type'        => $type,
        'status'      => $status,
        'amount'      => $amount,
        'active'      => true,
    ]);
}

describe('store: período no futuro', function () {
    it('recusa fim do período depois de hoje e não grava o fechamento', function () {
        $this->from(route('panel.financial.cash-closing.index'))
            ->post(route('panel.financial.cash-closing.store'), [
                'period_start' => now()->startOfMonth()->toDateString(),
                'period_end'   => now()->addDay()->toDateString(),
            ])
            ->assertRedirect(route('panel.financial.cash-closing.index'))
            ->assertSessionHasErrors(['period_end' => __('financial_cash_closing.validation.period_end_future')]);

        expect(CashClose::query()->where('entity_id', $this->entity->id)->count())->toBe(0);
    });

    it('recusa início no futuro também', function () {
        $this->post(route('panel.financial.cash-closing.store'), [
            'period_start' => now()->addDays(2)->toDateString(),
            'period_end'   => now()->addDays(3)->toDateString(),
        ])->assertSessionHasErrors(['period_start', 'period_end']);

        expect(CashClose::query()->where('entity_id', $this->entity->id)->count())->toBe(0);
    });

    it('aceita fechar até hoje, inclusive', function () {
        $this->post(route('panel.financial.cash-closing.store'), [
            'period_start' => now()->startOfMonth()->toDateString(),
            'period_end'   => now()->toDateString(),
        ])->assertRedirect()
            ->assertSessionHasNoErrors()
            ->assertSessionHas('message', __('financial_cash_closing.closed'));

        expect(CashClose::query()->where('entity_id', $this->entity->id)->count())->toBe(1);
    });

    it('mantém a recusa de sobreposição com mensagem no período', function () {
        app(CashClosingService::class)->closePeriod($this->entity->id, '2026-06-01', '2026-06-30');

        $this->post(route('panel.financial.cash-closing.store'), [
            'period_start' => '2026-06-15',
            'period_end'   => '2026-07-15',
        ])->assertSessionHasErrors(['period_start' => __('financial.cash_period_overlap')]);
    });
});

describe('index', function () {
    it('não derruba a página com datas inválidas na URL e devolve os filtros normalizados', function () {
        $this->get(route('panel.financial.cash-closing.index') . '?from=abc&to[]=x')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Panel/Financial/CashClosing/Index')
                ->where('filters.from', now()->startOfMonth()->toDateString())
                ->where('filters.to', now()->toDateString())
                ->where('today', now()->toDateString()));
    });

    it('prévia mantém os totais de summary() e soma contagem e aviso de sobreposição', function () {
        cashClosingHttpEntry($this, '2026-05-10', FinancialEntryType::Income->value, 100);
        cashClosingHttpEntry($this, '2026-05-11', FinancialEntryType::Income->value, 40, FinancialEntryStatus::Pending->value);
        cashClosingHttpEntry($this, '2026-05-12', FinancialEntryType::Expense->value, 30);
        cashClosingHttpEntry($this, '2026-05-13', FinancialEntryType::Income->value, 999, FinancialEntryStatus::Cancelled->value);

        app(CashClosingService::class)->closePeriod($this->entity->id, '2026-05-20', '2026-05-25');

        $this->get(route('panel.financial.cash-closing.index', ['from' => '2026-05-01', 'to' => '2026-05-31']))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('preview.income', 140)
                ->where('preview.expense', 30)
                ->where('preview.balance', 110)
                ->where('preview.pending', 40)
                ->where('preview.entries_count', 3)
                ->where('preview.overlaps', true));

        $this->get(route('panel.financial.cash-closing.index', ['from' => '2026-05-01', 'to' => '2026-05-15']))
            ->assertInertia(fn ($page) => $page->where('preview.overlaps', false));
    });

    it('prévia soma a despesa pendente (a pagar) e a contagem de pendentes, sem mudar pending (a receber)', function () {
        cashClosingHttpEntry($this, '2026-05-10', FinancialEntryType::Income->value, 40, FinancialEntryStatus::Pending->value);
        cashClosingHttpEntry($this, '2026-05-11', FinancialEntryType::Expense->value, 75.5, FinancialEntryStatus::Pending->value);
        cashClosingHttpEntry($this, '2026-05-12', FinancialEntryType::Expense->value, 20, FinancialEntryStatus::Pending->value);
        cashClosingHttpEntry($this, '2026-05-13', FinancialEntryType::Expense->value, 30);
        cashClosingHttpEntry($this, '2026-05-14', FinancialEntryType::Expense->value, 999, FinancialEntryStatus::Cancelled->value);
        cashClosingHttpEntry($this, '2026-06-01', FinancialEntryType::Expense->value, 500, FinancialEntryStatus::Pending->value);
        cashClosingHttpEntry($this, '2026-05-15', FinancialEntryType::Expense->value, 60, FinancialEntryStatus::Pending->value)->delete();

        // Pendente de outra clínica no mesmo período não entra.
        $other = Entity::factory()->create(['is_client' => true, 'active' => true]);
        FinancialCashEntry::query()->create([
            'entity_id' => $other->id, 'entry_date' => '2026-05-11', 'description' => 'Outra clínica',
            'type'      => FinancialEntryType::Expense->value, 'status' => FinancialEntryStatus::Pending->value,
            'amount'    => 700, 'active' => true,
        ]);

        $this->get(route('panel.financial.cash-closing.index', ['from' => '2026-05-01', 'to' => '2026-05-31']))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('preview.income', 40)
                ->where('preview.expense', 125.5)
                ->where('preview.balance', -85.5)
                ->where('preview.pending', 40)
                ->where('preview.pending_expense', 95.5)
                ->where('preview.pending_count', 3)
                ->where('preview.entries_count', 4));
    });

    it('histórico traz quem fechou, receitas/despesas e fechado em ISO 8601', function () {
        $this->post(route('panel.financial.cash-closing.store'), [
            'period_start' => now()->startOfMonth()->toDateString(),
            'period_end'   => now()->toDateString(),
            'notes'        => 'Conferido',
        ])->assertSessionHasNoErrors();

        $close = CashClose::query()->where('entity_id', $this->entity->id)->firstOrFail();

        $this->get(route('panel.financial.cash-closing.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('closes.data.0.id', $close->id)
                ->where('closes.data.0.closed_by_name', User::query()->findOrFail($this->entityUser->user_id)->name)
                ->where('closes.data.0.notes', 'Conferido')
                ->where('closes.data.0.closed_at', $close->closed_at->toIso8601String())
                ->has('closes.data.0.total_income')
                ->has('closes.data.0.total_expense')
                ->where('t.confirm_title', __('financial_cash_closing.confirm_title')));
    });

    it('não lista fechamentos de outra clínica', function () {
        $other = Entity::factory()->create(['is_client' => true, 'active' => true]);
        CashClose::query()->create([
            'entity_id'     => $other->id,
            'period_start'  => '2026-01-01',
            'period_end'    => '2026-01-31',
            'closed_at'     => now(),
            'total_income'  => 1,
            'total_expense' => 0,
            'balance'       => 1,
        ]);

        $this->get(route('panel.financial.cash-closing.index'))
            ->assertInertia(fn ($page) => $page->where('closes.total', 0));
    });
});

describe('destroy (reabrir)', function () {
    it('reabre com mensagem traduzida e mantém o histórico (soft delete)', function () {
        $close = app(CashClosingService::class)->closePeriod($this->entity->id, '2026-06-01', '2026-06-30');

        // Contrato da Fase 3: reabrir exige motivo (ReopenCashCloseRequest).
        $this->delete(route('panel.financial.cash-closing.destroy', $close->id), ['reason' => 'Recebimento lançado com valor errado'])
            ->assertRedirect()
            ->assertSessionHas('message', __('financial_cash_closing.reopened'));

        $reopened = CashClose::withTrashed()->find($close->id);
        expect($reopened->trashed())->toBeTrue()
            ->and($reopened->reopen_reason)->toBe('Recebimento lançado com valor errado');
    });

    it('não reabre fechamento de outra clínica', function () {
        $other = Entity::factory()->create(['is_client' => true, 'active' => true]);
        $close = CashClose::query()->create([
            'entity_id'     => $other->id,
            'period_start'  => '2026-01-01',
            'period_end'    => '2026-01-31',
            'closed_at'     => now(),
            'total_income'  => 1,
            'total_expense' => 0,
            'balance'       => 1,
        ]);

        $status = $this->delete(route('panel.financial.cash-closing.destroy', $close->id))->status();

        expect($status)->toBeIn([403, 404]);

        expect(CashClose::withTrashed()->find($close->id)->trashed())->toBeFalse();
    });
});
