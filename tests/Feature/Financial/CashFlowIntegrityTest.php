<?php

/*
 * Fluxo de caixa (panel/financial/cash-flow): integridade dos lançamentos
 * vinculados a guia, motivo de trava por linha, filtros normalizados,
 * categoria × tipo e mensagens traduzidas.
 */

use App\Enums\{BillingClaimStatus, FinancialEntryStatus, FinancialEntryType};
use App\Models\{BillingClaim, CashClose, Covenant, Entity, FinancialCashEntry, FinancialCategory};
use App\Services\Financial\CashClosingService;

beforeEach(function () {
    $this->entity   = Entity::factory()->create(['is_client' => true, 'active' => true]);
    $this->covenant = Covenant::factory()->create(['entity_id' => $this->entity->id, 'active' => true]);
    actingAsFinancialEntityUser($this->entity);
});

/** Entrada de caixa criada pelo recebimento de guia (como BillingService::markClaimPaid grava). */
function cashFlowIntegrityClaimEntry($test, string $date = '2026-06-10'): FinancialCashEntry
{
    $claim = BillingClaim::create([
        'entity_id'       => $test->entity->id,
        'covenant_id'     => $test->covenant->id,
        'status'          => BillingClaimStatus::Paid->value,
        'attendance_date' => $date,
        'amount'          => 250.00,
        'quantity'        => 1,
        'unit_price'      => 250.00,
    ]);

    return FinancialCashEntry::query()->create([
        'entity_id'        => $test->entity->id,
        'covenant_id'      => $test->covenant->id,
        'billing_claim_id' => $claim->id,
        'entry_date'       => $date,
        'description'      => 'Recebimento de guia',
        'type'             => FinancialEntryType::Income->value,
        'status'           => FinancialEntryStatus::Paid->value,
        'amount'           => 250.00,
        'reference_type'   => 'billing_claim',
        'reference_id'     => $claim->id,
        'active'           => true,
    ]);
}

function cashFlowIntegrityManualEntry($test, string $date = '2026-06-12', array $extra = []): FinancialCashEntry
{
    return FinancialCashEntry::query()->create(array_merge([
        'entity_id'   => $test->entity->id,
        'entry_date'  => $date,
        'description' => 'Consulta particular',
        'type'        => FinancialEntryType::Income->value,
        'status'      => FinancialEntryStatus::Pending->value,
        'amount'      => 180.00,
        'active'      => true,
    ], $extra));
}

describe('lançamento vinculado a guia (billing_claim_id)', function () {
    it('recusa PATCH com 422 traduzido e não altera o lançamento', function () {
        $entry = cashFlowIntegrityClaimEntry($this);

        $this->patchJson(route('panel.financial.cash-flow.update', $entry->id), [
            'entry_date'  => '2026-06-10',
            'description' => 'Alterado',
            'type'        => 'income',
            'status'      => 'paid',
            'amount'      => 1,
        ])->assertStatus(422)
            ->assertJsonPath('errors.billing_claim_id.0', __('financial_cash_flow.locked_by_claim'));

        $fresh = $entry->fresh();
        expect((float) $fresh->amount)->toBe(250.0)
            ->and($fresh->description)->toBe('Recebimento de guia');
    });

    it('recusa DELETE com 422 e mantém o lançamento', function () {
        $entry = cashFlowIntegrityClaimEntry($this);

        $this->deleteJson(route('panel.financial.cash-flow.destroy', $entry->id))
            ->assertStatus(422)
            ->assertJsonPath('errors.billing_claim_id.0', __('financial_cash_flow.locked_by_claim'));

        expect(FinancialCashEntry::withTrashed()->find($entry->id)->trashed())->toBeFalse();
    });

    it('recusa também a requisição Inertia (não-JSON) com erro na sessão', function () {
        $entry = cashFlowIntegrityClaimEntry($this);

        $this->from(route('panel.financial.cash-flow.index'))
            ->delete(route('panel.financial.cash-flow.destroy', $entry->id))
            ->assertRedirect(route('panel.financial.cash-flow.index'))
            ->assertSessionHasErrors(['billing_claim_id']);

        expect(FinancialCashEntry::find($entry->id))->not->toBeNull();
    });

    it('continua permitindo editar e excluir lançamentos avulsos', function () {
        $entry = cashFlowIntegrityManualEntry($this, now()->toDateString());

        $this->patchJson(route('panel.financial.cash-flow.update', $entry->id), [
            'entry_date'  => now()->toDateString(),
            'description' => 'Consulta particular',
            'type'        => 'income',
            'status'      => 'paid',
            'amount'      => 180,
        ])->assertOk()->assertJsonPath('message', __('financial_cash_flow.updated'));

        expect($entry->fresh()->status)->toBe(FinancialEntryStatus::Paid);

        $this->deleteJson(route('panel.financial.cash-flow.destroy', $entry->id))
            ->assertOk()->assertJsonPath('message', __('financial_cash_flow.destroyed'));
    });
});

describe('PATCH com o payload do modal pré-preenchido', function () {
    it('dá baixa (pendente → pago) preservando data, valor, categoria e campos fora do modal', function () {
        $category = FinancialCategory::query()->create([
            'entity_id' => $this->entity->id, 'name' => 'Consultas', 'type' => 'income', 'active' => true,
        ]);
        $entry = cashFlowIntegrityManualEntry($this, now()->subDay()->toDateString(), [
            'category_id'    => $category->id,
            'covenant_id'    => $this->covenant->id,
            'payment_method' => 'transfer',
            'notes'          => 'Obs original',
        ]);

        // Exatamente o que o CashEntryFormModal envia após pré-preencher com a linha.
        $this->patchJson(route('panel.financial.cash-flow.update', $entry->id), [
            'entry_date'  => $entry->entry_date->toDateString(),
            'description' => 'Consulta particular',
            'type'        => 'income',
            'status'      => 'paid',
            'amount'      => 180,
            'category_id' => $category->id,
            'notes'       => 'Obs original',
        ])->assertOk();

        $fresh = $entry->fresh();
        expect($fresh->status)->toBe(FinancialEntryStatus::Paid)
            ->and($fresh->entry_date->toDateString())->toBe(now()->subDay()->toDateString())
            ->and((float) $fresh->amount)->toBe(180.0)
            ->and($fresh->category_id)->toBe($category->id)
            ->and($fresh->covenant_id)->toBe($this->covenant->id)
            ->and($fresh->notes)->toBe('Obs original');
    });
});

describe('categoria × tipo', function () {
    it('recusa categoria de receita num lançamento de despesa', function () {
        $incomeCategory = FinancialCategory::query()->create([
            'entity_id' => $this->entity->id, 'name' => 'Consultas', 'type' => 'income', 'active' => true,
        ]);

        $this->postJson(route('panel.financial.cash-flow.store'), [
            'entry_date'  => now()->toDateString(),
            'description' => 'Aluguel',
            'type'        => 'expense',
            'amount'      => 1500,
            'category_id' => $incomeCategory->id,
        ])->assertStatus(422)
            ->assertJsonPath('errors.category_id.0', __('financial_cash_flow.category_type_mismatch'));

        expect(FinancialCashEntry::where('entity_id', $this->entity->id)->count())->toBe(0);
    });

    it('aceita categoria do mesmo tipo', function () {
        $expenseCategory = FinancialCategory::query()->create([
            'entity_id' => $this->entity->id, 'name' => 'Aluguel', 'type' => 'expense', 'active' => true,
        ]);

        $this->postJson(route('panel.financial.cash-flow.store'), [
            'entry_date'  => now()->toDateString(),
            'description' => 'Aluguel',
            'type'        => 'expense',
            'amount'      => 1500,
            'category_id' => $expenseCategory->id,
        ])->assertOk()->assertJsonPath('message', __('financial_cash_flow.created'));
    });

    it('recusa categoria de outra clínica mesmo com o tipo certo (escopo de tenant preservado)', function () {
        $other    = Entity::factory()->create(['is_client' => true, 'active' => true]);
        $category = FinancialCategory::query()->create([
            'entity_id' => $other->id, 'name' => 'Aluguel', 'type' => 'expense', 'active' => true,
        ]);

        $this->postJson(route('panel.financial.cash-flow.store'), [
            'entry_date'  => now()->toDateString(),
            'description' => 'Aluguel',
            'type'        => 'expense',
            'amount'      => 10,
            'category_id' => $category->id,
        ])->assertStatus(422)->assertJsonValidationErrors(['category_id']);
    });
});

describe('período fechado', function () {
    it('devolve o motivo também em errors.entry_date para o modal exibir no campo', function () {
        app(CashClosingService::class)->closePeriod($this->entity->id, '2026-06-01', '2026-06-30');

        $this->postJson(route('panel.financial.cash-flow.store'), [
            'entry_date'  => '2026-06-15',
            'description' => 'x',
            'type'        => 'income',
            'amount'      => 50,
        ])->assertStatus(422)
            ->assertJsonPath('message', __('financial.cash_period_closed'))
            ->assertJsonPath('errors.entry_date.0', __('financial.cash_period_closed'));
    });
});

describe('index: lock_reason por linha e aviso de período fechado', function () {
    it('marca billing_claim, closed_period ou null em cada linha e lista os fechamentos do período', function () {
        $claimEntry  = cashFlowIntegrityClaimEntry($this, '2026-06-20');
        $closedEntry = cashFlowIntegrityManualEntry($this, '2026-06-05');
        $freeEntry   = cashFlowIntegrityManualEntry($this, '2026-06-25');

        CashClose::query()->create([
            'entity_id'     => $this->entity->id,
            'period_start'  => '2026-06-01',
            'period_end'    => '2026-06-10',
            'closed_at'     => now(),
            'total_income'  => 0,
            'total_expense' => 0,
            'balance'       => 0,
        ]);

        $this->get(route('panel.financial.cash-flow.index', ['from' => '2026-06-01', 'to' => '2026-06-30']))
            ->assertOk()
            ->assertInertia(function ($page) use ($claimEntry, $closedEntry, $freeEntry) {
                $page->component('Panel/Financial/CashFlow/Index')
                    ->where('closed_periods', [['period_start' => '2026-06-01', 'period_end' => '2026-06-10']])
                    ->where('today', now()->toDateString())
                    ->where('t.lock_billing_claim', __('financial_cash_flow.lock_billing_claim'));

                $rows = collect($page->toArray()['props']['entries']['data'])->keyBy('id');

                expect($rows[$claimEntry->id]['lock_reason'])->toBe('billing_claim')
                    ->and($rows[$claimEntry->id]['has_claim'])->toBeTrue()
                    ->and($rows[$closedEntry->id]['lock_reason'])->toBe('closed_period')
                    ->and($rows[$freeEntry->id]['lock_reason'])->toBeNull();
            });
    });

    it('não conta fechamento reaberto (soft delete) como trava', function () {
        $entry = cashFlowIntegrityManualEntry($this, '2026-06-05');
        $close = app(CashClosingService::class)->closePeriod($this->entity->id, '2026-06-01', '2026-06-10');
        app(CashClosingService::class)->reopen($close, 'Correção de lançamento do período', null);

        $this->get(route('panel.financial.cash-flow.index', ['from' => '2026-06-01', 'to' => '2026-06-30']))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('closed_periods', [])
                ->where('entries.data.0.id', $entry->id)
                ->where('entries.data.0.lock_reason', null));
    });
});

describe('index: filtros inválidos na URL', function () {
    it('não derruba a página (sem 500) e devolve os filtros normalizados', function () {
        $this->get(route('panel.financial.cash-flow.index') . '?from=abc&to=2026-13-45&type=foo&status[]=x&category_id=not-a-uuid')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Panel/Financial/CashFlow/Index')
                ->where('filters.from', now()->startOfMonth()->toDateString())
                ->where('filters.to', now()->toDateString())
                ->where('filters.type', null)
                ->where('filters.status', null)
                ->where('filters.category_id', null));
    });

    it('inverte o período quando from > to e mantém filtros válidos', function () {
        $this->get(route('panel.financial.cash-flow.index', [
            'from' => '2026-06-30', 'to' => '2026-06-01', 'type' => 'expense', 'status' => 'pending',
        ]))->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('filters.from', '2026-06-01')
                ->where('filters.to', '2026-06-30')
                ->where('filters.type', 'expense')
                ->where('filters.status', 'pending'));
    });
});

describe('i18n', function () {
    it('traduz os rótulos dos enums de tipo e status (pt_BR inalterado)', function () {
        app()->setLocale('pt_BR');
        expect(FinancialEntryType::Income->label())->toBe('Receita')
            ->and(FinancialEntryType::Expense->label())->toBe('Despesa')
            ->and(FinancialEntryStatus::Pending->label())->toBe('Pendente')
            ->and(FinancialEntryStatus::Paid->label())->toBe('Pago')
            ->and(FinancialEntryStatus::Cancelled->label())->toBe('Cancelado');

        app()->setLocale('en');
        expect(FinancialEntryType::Income->label())->toBe('Income')
            ->and(FinancialEntryStatus::Cancelled->label())->toBe('Cancelled');
    });

    it('mantém as mesmas chaves em pt_BR e en nos arquivos de tradução das telas de caixa', function () {
        $flatten = function (array $items, string $prefix = '') use (&$flatten): array {
            $keys = [];

            foreach ($items as $key => $value) {
                $keys = is_array($value)
                    ? array_merge($keys, $flatten($value, "{$prefix}{$key}."))
                    : array_merge($keys, ["{$prefix}{$key}"]);
            }

            return $keys;
        };

        foreach (['financial_cash_flow', 'financial_cash_closing'] as $file) {
            $pt = $flatten(require lang_path("pt_BR/{$file}.php"));
            $en = $flatten(require lang_path("en/{$file}.php"));
            sort($pt);
            sort($en);

            expect($en)->toBe($pt, "Chaves divergentes em {$file}.php");
        }
    });
});
