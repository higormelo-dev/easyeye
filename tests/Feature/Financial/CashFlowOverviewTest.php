<?php

declare(strict_types=1);

/*
 * Fluxo de caixa — Fase 3: KPIs via CashFlowService::overview() com os MESMOS
 * filtros da tabela (cancelados fora), busca por descrição/código com curingas
 * literais, ordenação por whitelist, colunas novas por linha (código, paciente,
 * forma, origem) e trava de valor/forma do recebimento da agenda com pagamento
 * dividido. Tudo escopado pela clínica da sessão.
 */

use App\Enums\{CashEntryReferenceType, ClientRule, FinancialEntryStatus, FinancialEntryType, PaymentMethod};
use App\Models\{BillingClaim, Covenant, Entity, FinancialCashEntry, FinancialCategory, Patient, People, User};
use App\Services\Financial\CashFlowService;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->entity     = Entity::factory()->create(['is_client' => true, 'active' => true]);
    $this->entityUser = actingAsFinancialEntityUser($this->entity);
    $this->period     = ['from' => '2026-09-01', 'to' => '2026-09-30'];
});

function cashFlowOverviewEntry(Entity $entity, array $attrs = []): FinancialCashEntry
{
    return FinancialCashEntry::query()->create(array_merge([
        'entity_id'   => $entity->id,
        'entry_date'  => '2026-09-10',
        'description' => 'Lançamento',
        'type'        => FinancialEntryType::Income->value,
        'status'      => FinancialEntryStatus::Paid->value,
        'amount'      => 100,
        'active'      => true,
    ], $attrs));
}

/** Cenário padrão: 2 receitas pagas, 1 a receber, 1 despesa paga, 1 a pagar, 1 cancelada. */
function cashFlowOverviewScenario($test): void
{
    cashFlowOverviewEntry($test->entity, ['description' => 'Consulta A', 'amount' => 200]);
    cashFlowOverviewEntry($test->entity, ['description' => 'Consulta B', 'amount' => 150.5]);
    cashFlowOverviewEntry($test->entity, ['description' => 'Consulta C', 'amount' => 80, 'status' => FinancialEntryStatus::Pending->value]);
    cashFlowOverviewEntry($test->entity, ['description' => 'Aluguel', 'amount' => 300, 'type' => FinancialEntryType::Expense->value]);
    cashFlowOverviewEntry($test->entity, ['description' => 'Luz', 'amount' => 45.25, 'type' => FinancialEntryType::Expense->value, 'status' => FinancialEntryStatus::Pending->value]);
    cashFlowOverviewEntry($test->entity, ['description' => 'Cancelada', 'amount' => 999, 'status' => FinancialEntryStatus::Cancelled->value]);
}

function cashFlowOverviewProps($test, array $query = []): array
{
    return $test->get(route('panel.financial.cash-flow.index', array_merge($test->period, $query)))
        ->assertOk()
        ->viewData('page')['props'];
}

describe('CashFlowService::overview', function () {
    it('sem filtros: recebido, a receber, pago, a pagar, saldos e totais do rodapé (cancelado fora)', function () {
        cashFlowOverviewScenario($this);

        $overview = app(CashFlowService::class)->overview($this->entity->id, '2026-09-01', '2026-09-30');

        expect($overview)->toBe([
            'received'          => 350.5,
            'receivable'        => 80.0,
            'paid'              => 300.0,
            'payable'           => 45.25,
            'realized_balance'  => 50.5,
            'projected_balance' => 85.25,
            'income_total'      => 430.5,
            'expense_total'     => 345.25,
            'entries_count'     => 5,
        ]);
    });

    it('ignora outra clínica, fora do período e excluídos (soft delete)', function () {
        cashFlowOverviewEntry($this->entity, ['amount' => 10]);
        cashFlowOverviewEntry($this->entity, ['amount' => 1000, 'entry_date' => '2026-10-01']);
        cashFlowOverviewEntry($this->entity, ['amount' => 2000])->delete();

        $other = Entity::factory()->create(['is_client' => true, 'active' => true]);
        cashFlowOverviewEntry($other, ['amount' => 5000]);

        $overview = app(CashFlowService::class)->overview($this->entity->id, '2026-09-01', '2026-09-30');

        expect($overview['received'])->toBe(10.0)
            ->and($overview['entries_count'])->toBe(1);
    });

    it('com filtros: segue tipo, status, categoria e busca da tabela', function () {
        cashFlowOverviewScenario($this);
        $category = FinancialCategory::query()->create(['entity_id' => $this->entity->id, 'name' => 'Consultas', 'type' => 'income', 'active' => true]);
        cashFlowOverviewEntry($this->entity, ['description' => 'Retorno', 'amount' => 60, 'category_id' => $category->id]);

        $service = app(CashFlowService::class);

        $expenses = $service->overview($this->entity->id, '2026-09-01', '2026-09-30', ['type' => 'expense']);
        expect($expenses['received'])->toBe(0.0)
            ->and($expenses['paid'])->toBe(300.0)
            ->and($expenses['payable'])->toBe(45.25)
            ->and($expenses['entries_count'])->toBe(2);

        $pending = $service->overview($this->entity->id, '2026-09-01', '2026-09-30', ['status' => 'pending']);
        expect($pending['receivable'])->toBe(80.0)
            ->and($pending['payable'])->toBe(45.25)
            ->and($pending['received'])->toBe(0.0)
            ->and($pending['projected_balance'])->toBe(34.75);

        $byCategory = $service->overview($this->entity->id, '2026-09-01', '2026-09-30', ['category_id' => $category->id]);
        expect($byCategory['received'])->toBe(60.0)->and($byCategory['entries_count'])->toBe(1);

        $search = $service->overview($this->entity->id, '2026-09-01', '2026-09-30', ['search' => 'consulta']);
        expect($search['received'])->toBe(350.5)
            ->and($search['receivable'])->toBe(80.0)
            ->and($search['entries_count'])->toBe(3);

        // Filtro de cancelados: KPIs zerados (cancelado nunca entra).
        $cancelled = $service->overview($this->entity->id, '2026-09-01', '2026-09-30', ['status' => 'cancelled']);
        expect($cancelled['entries_count'])->toBe(0)->and($cancelled['income_total'])->toBe(0.0);
    });

    it('não mexe no summary() (snapshot, relatórios e BI continuam com as mesmas chaves e valores)', function () {
        cashFlowOverviewScenario($this);

        expect(app(CashFlowService::class)->summary($this->entity->id, '2026-09-01', '2026-09-30'))->toBe([
            'income'  => 430.5,
            'expense' => 345.25,
            'balance' => 85.25,
            'pending' => 80.0,
        ]);
    });
});

describe('index: KPIs, filtros e busca', function () {
    it('manda overview com os mesmos filtros da tabela e devolve os filtros normalizados', function () {
        cashFlowOverviewScenario($this);

        $props = cashFlowOverviewProps($this, ['type' => 'income', 'status' => 'paid']);

        expect($props['overview']['received'])->toBe(350.5)
            ->and($props['overview']['receivable'])->toBe(0.0)
            ->and($props['overview']['entries_count'])->toBe(2)
            ->and($props['entries']['total'])->toBe(2)
            ->and($props['filters'])->toMatchArray([
                'type' => 'income', 'status' => 'paid', 'category_id' => null, 'search' => '', 'sort' => 'entry_date', 'direction' => 'desc',
            ])
            ->and($props)->not->toHaveKey('summary')
            ->and($props['t']['shared']['period']['from'])->toBe(__('financial_shared.period.from'));
    });

    it('busca por descrição (sem acento/caixa) e pelo código FLC', function () {
        $consulta = cashFlowOverviewEntry($this->entity, ['description' => 'CONSULTA Pediátrica']);
        cashFlowOverviewEntry($this->entity, ['description' => 'Aluguel']);

        $byDescription = collect(cashFlowOverviewProps($this, ['search' => 'pediatrica'])['entries']['data'])->pluck('id');
        expect($byDescription->all())->toBe([$consulta->id]);

        $byCode = collect(cashFlowOverviewProps($this, ['search' => $consulta->code])['entries']['data'])->pluck('id');
        expect($byCode->all())->toBe([$consulta->id]);
    });

    it('trata %, _ e \\ da busca como texto (não curinga)', function () {
        $percent    = cashFlowOverviewEntry($this->entity, ['description' => 'Desconto 10% à vista']);
        $hundred    = cashFlowOverviewEntry($this->entity, ['description' => 'Desconto 100 reais']);
        $underscore = cashFlowOverviewEntry($this->entity, ['description' => 'Taxa_boleto']);
        $plain      = cashFlowOverviewEntry($this->entity, ['description' => 'Taxa boleto']);
        $backslash  = cashFlowOverviewEntry($this->entity, ['description' => 'Pasta C:\\caixa']);

        $ids = fn (string $search) => collect(cashFlowOverviewProps($this, ['search' => $search])['entries']['data'])->pluck('id')->sort()->values()->all();

        expect($ids('10%'))->toBe([$percent->id])
            ->and($ids('%'))->toBe([$percent->id])
            ->and($ids('taxa_'))->toBe([$underscore->id])
            ->and($ids('_'))->toBe([$underscore->id])
            ->and($ids('C:\\'))->toBe([$backslash->id])
            ->and(in_array($hundred->id, $ids('Desconto'), true))->toBeTrue()
            ->and(in_array($plain->id, $ids('taxa'), true))->toBeTrue();
    });

    it('busca longa é cortada em 100 caracteres e nunca vira erro', function () {
        cashFlowOverviewEntry($this->entity);

        $props = cashFlowOverviewProps($this, ['search' => str_repeat('a', 300)]);

        expect(mb_strlen($props['filters']['search']))->toBe(100)
            ->and($props['entries']['total'])->toBe(0);

        $this->get(route('panel.financial.cash-flow.index', ['search' => ['x' => 'y']]))->assertOk();
    });

    it('ordena só pelas colunas da whitelist; valor inválido cai no padrão (data desc)', function () {
        $small = cashFlowOverviewEntry($this->entity, ['amount' => 5, 'entry_date' => '2026-09-20', 'description' => 'B']);
        $big   = cashFlowOverviewEntry($this->entity, ['amount' => 900, 'entry_date' => '2026-09-05', 'description' => 'A']);

        $byAmount = cashFlowOverviewProps($this, ['sort' => 'amount', 'direction' => 'asc']);
        expect(collect($byAmount['entries']['data'])->pluck('id')->all())->toBe([$small->id, $big->id])
            ->and($byAmount['filters']['sort'])->toBe('amount')
            ->and($byAmount['filters']['direction'])->toBe('asc');

        $byDescription = cashFlowOverviewProps($this, ['sort' => 'description', 'direction' => 'desc']);
        expect(collect($byDescription['entries']['data'])->pluck('id')->all())->toBe([$small->id, $big->id]);

        foreach (['amount; drop table users', 'entity_id', 'deleted_at'] as $sort) {
            $fallback = cashFlowOverviewProps($this, ['sort' => $sort, 'direction' => 'sideways']);

            expect($fallback['filters']['sort'])->toBe('entry_date')
                ->and($fallback['filters']['direction'])->toBe('desc')
                ->and(collect($fallback['entries']['data'])->pluck('id')->all())->toBe([$small->id, $big->id]);
        }
    });

    it('não mostra lançamentos de outra clínica nem com categoria dela no filtro', function () {
        $other         = Entity::factory()->create(['is_client' => true, 'active' => true]);
        $otherCategory = FinancialCategory::query()->create(['entity_id' => $other->id, 'name' => 'X', 'type' => 'income', 'active' => true]);
        cashFlowOverviewEntry($other, ['description' => 'De outra clínica', 'category_id' => $otherCategory->id]);

        $props = cashFlowOverviewProps($this, ['category_id' => $otherCategory->id, 'search' => 'outra']);

        expect($props['entries']['total'])->toBe(0)
            ->and($props['overview']['entries_count'])->toBe(0);
    });
});

describe('index: colunas por linha', function () {
    it('código, paciente (só o nome, só da clínica), forma pelo label do enum e origem', function () {
        $patient = Patient::factory()->create([
            'entity_id' => $this->entity->id,
            'person_id' => People::factory()->create(['full_name' => 'Maria da Silva'])->id,
        ]);
        ['schedule' => $schedule] = createScheduleForEntity($this->entity, ['date_time' => '2026-09-08 09:30:00']);

        $fromSchedule = cashFlowOverviewEntry($this->entity, [
            'patient_id'     => $patient->id, 'payment_method' => PaymentMethod::CreditCash->value,
            'amount_cash'    => 40, 'amount_credit' => 60,
            'reference_type' => CashEntryReferenceType::Schedule->value, 'reference_id' => $schedule->id,
        ]);
        $purchase = cashFlowOverviewEntry($this->entity, [
            'type'           => FinancialEntryType::Expense->value,
            'reference_type' => CashEntryReferenceType::PurchaseOrder->value, 'reference_id' => (string) Str::uuid(),
        ]);
        $manual = cashFlowOverviewEntry($this->entity, ['payment_method' => 'transferencia-legado']);

        // Paciente de outra clínica vinculado por dado legado/forjado: nome não vaza.
        $other        = Entity::factory()->create(['is_client' => true, 'active' => true]);
        $otherPatient = Patient::factory()->create(['entity_id' => $other->id]);
        $leak         = cashFlowOverviewEntry($this->entity, ['patient_id' => $otherPatient->id]);

        $rows = collect(cashFlowOverviewProps($this)['entries']['data'])->keyBy('id');

        expect($rows[$fromSchedule->id])->toMatchArray([
            'code'                 => $fromSchedule->code,
            'patient_name'         => 'MARIA DA SILVA',
            'payment_method'       => 'credit_cash',
            'payment_method_label' => PaymentMethod::CreditCash->label(),
            'origin'               => 'schedule',
            'schedule_date'        => '2026-09-08',
            'has_split'            => true,
        ])
            ->and($rows[$fromSchedule->id])->not->toHaveKey('patient_id')
            ->and($rows[$purchase->id]['origin'])->toBe('purchase')
            ->and($rows[$manual->id]['origin'])->toBe('manual')
            ->and($rows[$manual->id]['payment_method'])->toBeNull()
            ->and($rows[$manual->id]['payment_method_label'])->toBeNull()
            ->and($rows[$leak->id]['patient_name'])->toBeNull();
    });

    it('recebimento de guia aparece com origem "claim"', function () {
        $covenant = Covenant::factory()->create(['entity_id' => $this->entity->id, 'active' => true]);
        $claim    = BillingClaim::create([
            'entity_id'       => $this->entity->id, 'covenant_id' => $covenant->id, 'status' => 'paid',
            'attendance_date' => '2026-09-10', 'amount' => 250, 'quantity' => 1, 'unit_price' => 250,
        ]);
        $entry = cashFlowOverviewEntry($this->entity, [
            'billing_claim_id' => $claim->id, 'reference_type' => CashEntryReferenceType::BillingClaim->value, 'reference_id' => $claim->id,
        ]);

        $row = collect(cashFlowOverviewProps($this)['entries']['data'])->firstWhere('id', $entry->id);

        expect($row['origin'])->toBe('claim')->and($row['lock_reason'])->toBe('billing_claim');
    });

    it('manda convênios da clínica (e globais) ativos, formas de pagamento e acesso à agenda', function () {
        $own      = Covenant::factory()->create(['entity_id' => $this->entity->id, 'active' => true, 'name' => 'PROPRIO']);
        $inactive = Covenant::factory()->create(['entity_id' => $this->entity->id, 'active' => false]);
        $foreign  = Covenant::factory()->create(['entity_id' => Entity::factory()->create()->id, 'active' => true]);

        $props = cashFlowOverviewProps($this);
        $ids   = collect($props['covenants'])->pluck('id');

        expect($ids)->toContain($own->id)
            ->and($ids)->not->toContain($inactive->id)
            ->and($ids)->not->toContain($foreign->id)
            ->and(collect($props['payment_methods'])->pluck('value')->all())->toBe(array_column(PaymentMethod::cases(), 'value'))
            ->and($props['payment_methods'][0]['label'])->toBe(PaymentMethod::Cash->label())
            ->and($props['can_edit_schedule'])->toBeTrue();
    });
});

describe('acesso', function () {
    it('perfil sem acesso financeiro recebe 403 na listagem', function () {
        $user       = User::factory()->create();
        $entityUser = createEntityUser($this->entity, $user, ClientRule::Doctor->value);

        $this->actingAs($user)->withSession(panelSession($entityUser))
            ->get(route('panel.financial.cash-flow.index'))
            ->assertForbidden();
    });

    it('financeiro vê a tela, mas sem o link da agenda (não acessa a agenda)', function () {
        $user       = User::factory()->create();
        $entityUser = createEntityUser($this->entity, $user, ClientRule::Financial->value);

        $props = $this->actingAs($user)->withSession(panelSession($entityUser))
            ->get(route('panel.financial.cash-flow.index'))
            ->assertOk()
            ->viewData('page')['props'];

        expect($props['can_edit_schedule'])->toBeFalse();
    });
});

describe('PATCH: recebimento da agenda com pagamento dividido', function () {
    beforeEach(function () {
        ['schedule' => $schedule] = createScheduleForEntity($this->entity);

        $this->split = cashFlowOverviewEntry($this->entity, [
            'entry_date'     => now()->toDateString(), 'description' => 'Consulta', 'status' => FinancialEntryStatus::Pending->value,
            'amount'         => 300, 'amount_cash' => 100, 'amount_credit' => 200, 'payment_method' => PaymentMethod::CreditCash->value,
            'reference_type' => CashEntryReferenceType::Schedule->value, 'reference_id' => $schedule->id,
        ]);
        $this->payload = [
            'entry_date' => now()->toDateString(), 'description' => 'Consulta', 'type' => 'income', 'status' => 'paid', 'amount' => 300,
        ];
    });

    it('dar baixa (mesmo valor, sem reenviar a forma) continua permitido', function () {
        $this->patchJson(route('panel.financial.cash-flow.update', $this->split->id), $this->payload)->assertOk();

        $fresh = $this->split->fresh();
        expect($fresh->status)->toBe(FinancialEntryStatus::Paid)
            ->and((float) $fresh->amount)->toBe(300.0)
            ->and($fresh->payment_method)->toBe(PaymentMethod::CreditCash);
    });

    it('recusa mudar o valor com 422 traduzido', function () {
        $this->patchJson(route('panel.financial.cash-flow.update', $this->split->id), array_merge($this->payload, ['amount' => 250]))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['amount' => __('financial_cash_flow.schedule_split_locked')]);

        expect((float) $this->split->fresh()->amount)->toBe(300.0);
    });

    it('recusa mudar a forma de pagamento com 422 traduzido', function () {
        $this->patchJson(route('panel.financial.cash-flow.update', $this->split->id), array_merge($this->payload, ['payment_method' => 'cash']))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['payment_method' => __('financial_cash_flow.schedule_split_locked')]);

        expect($this->split->fresh()->payment_method)->toBe(PaymentMethod::CreditCash);
    });

    it('lançamento avulso continua com valor e forma editáveis', function () {
        $manual = cashFlowOverviewEntry($this->entity, ['entry_date' => now()->toDateString(), 'amount' => 90, 'payment_method' => 'cash']);

        $this->patchJson(route('panel.financial.cash-flow.update', $manual->id), array_merge($this->payload, ['amount' => 95, 'payment_method' => 'credit']))
            ->assertOk();

        expect((float) $manual->fresh()->amount)->toBe(95.0)
            ->and($manual->fresh()->payment_method)->toBe(PaymentMethod::Credit);
    });
});

describe('POST: forma de pagamento e convênio', function () {
    it('grava forma e convênio da clínica; recusa convênio de outra clínica (422)', function () {
        $own     = Covenant::factory()->create(['entity_id' => $this->entity->id, 'active' => true]);
        $foreign = Covenant::factory()->create(['entity_id' => Entity::factory()->create()->id, 'active' => true]);
        $payload = ['entry_date' => now()->toDateString(), 'description' => 'Consulta', 'type' => 'income', 'amount' => 120];

        $this->postJson(route('panel.financial.cash-flow.store'), $payload + ['payment_method' => 'debit_cash', 'covenant_id' => $own->id])
            ->assertOk();

        $entry = FinancialCashEntry::query()->where('entity_id', $this->entity->id)->sole();
        expect($entry->payment_method)->toBe(PaymentMethod::DebitCash)
            ->and($entry->covenant_id)->toBe($own->id);

        $this->postJson(route('panel.financial.cash-flow.store'), $payload + ['covenant_id' => $foreign->id])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['covenant_id']);
    });
});
