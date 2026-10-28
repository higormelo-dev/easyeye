<?php

declare(strict_types=1);

/*
 * php artisan financial:audit-cash-references — relatório SOMENTE LEITURA das
 * referências de sistema dos lançamentos de caixa gravadas antes da correção
 * do CashEntryRequest (reference_type livre + reference_id sem escopo).
 */

use App\Enums\{BillingClaimStatus, CashEntryReferenceType, FinancialEntryStatus, FinancialEntryType};
use App\Models\{BillingClaim, Covenant, Entity, FinancialCashEntry, PurchaseOrder, Supplier, User};
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->entity      = Entity::factory()->create(['is_client' => true, 'active' => true]);
    $this->otherEntity = Entity::factory()->create(['is_client' => true, 'active' => true]);

    ['schedule' => $this->schedule]      = createScheduleForEntity($this->entity);
    ['schedule' => $this->otherSchedule] = createScheduleForEntity($this->otherEntity);

    $this->claim = BillingClaim::create([
        'entity_id'       => $this->entity->id,
        'covenant_id'     => Covenant::factory()->create(['entity_id' => $this->entity->id])->id,
        'status'          => BillingClaimStatus::Paid->value,
        'attendance_date' => now()->toDateString(),
        'amount'          => 100,
        'quantity'        => 1,
        'unit_price'      => 100,
    ]);
});

function auditRefEntry(Entity $entity, array $extra = []): FinancialCashEntry
{
    return FinancialCashEntry::query()->create(array_merge([
        'entity_id'   => $entity->id,
        'entry_date'  => now()->toDateString(),
        'description' => 'Paciente Maria da Silva — consulta',
        'notes'       => 'CPF 123.456.789-00',
        'type'        => FinancialEntryType::Income->value,
        'status'      => FinancialEntryStatus::Paid->value,
        'amount'      => 50,
        'active'      => true,
    ], $extra));
}

/**
 * Simula a linha legada gravada pela falha antiga: o model agora recusa tipo
 * fora da whitelist, então o valor forjado entra direto na tabela.
 */
function auditRefForge(FinancialCashEntry $entry, array $columns): FinancialCashEntry
{
    DB::table('financial_cash_entries')->where('id', $entry->id)->update($columns);

    return $entry;
}

/** Vínculos legítimos gravados pelos fluxos de sistema. */
function auditRefLegitEntries($test): array
{
    $supplier = Supplier::create(['entity_id' => $test->entity->id, 'name' => 'Fornecedor', 'active' => true]);
    $order    = PurchaseOrder::create(['entity_id' => $test->entity->id, 'supplier_id' => $supplier->id, 'order_date' => now()->toDateString()]);

    return [
        'manual'   => auditRefEntry($test->entity),
        'schedule' => auditRefEntry($test->entity, [
            'reference_type' => CashEntryReferenceType::Schedule->value,
            'reference_id'   => $test->schedule->id,
        ]),
        'claim' => auditRefEntry($test->entity, [
            'billing_claim_id' => $test->claim->id,
            'reference_type'   => CashEntryReferenceType::BillingClaim->value,
            'reference_id'     => $test->claim->id,
        ]),
        'purchase_order' => auditRefEntry($test->entity, [
            'type'           => FinancialEntryType::Expense->value,
            'reference_type' => PurchaseOrder::class,
            'reference_id'   => $order->id,
        ]),
    ];
}

/**
 * Uma fábrica por categoria (cria só a linha forjada daquela categoria).
 *
 * @return array<string, Closure(): FinancialCashEntry>
 */
function auditRefForgers($test): array
{
    return [
        'unknown_type' => fn () => auditRefForge(auditRefEntry($test->entity), [
            'reference_type' => User::class,
            'reference_id'   => User::factory()->create()->id,
        ]),
        'incomplete' => fn () => auditRefForge(auditRefEntry($test->entity), [
            'reference_id' => $test->schedule->id,
        ]),
        'missing_target' => fn () => auditRefEntry($test->entity, [
            'reference_type' => CashEntryReferenceType::Schedule->value,
            'reference_id'   => (string) Str::uuid(),
        ]),
        'cross_entity' => fn () => auditRefEntry($test->entity, [
            'reference_type' => CashEntryReferenceType::Schedule->value,
            'reference_id'   => $test->otherSchedule->id,
        ]),
        'claim_mismatch' => fn () => auditRefEntry($test->entity, [
            'reference_type' => CashEntryReferenceType::BillingClaim->value,
            'reference_id'   => $test->claim->id,
        ]),
    ];
}

/** @return array<string, FinancialCashEntry> uma linha forjada por categoria */
function auditRefForgedEntries($test): array
{
    return array_map(fn (Closure $forge) => $forge(), auditRefForgers($test));
}

function auditRefSnapshot(): array
{
    return DB::table('financial_cash_entries')->orderBy('id')->get()->map(fn ($row) => (array) $row)->all();
}

it('não acusa nada quando só existem vínculos de sistema legítimos', function () {
    auditRefLegitEntries($this);

    $this->artisan('financial:audit-cash-references')
        ->expectsOutputToContain(__('financial_cash_flow.audit_references.none'))
        ->assertExitCode(0);
});

it('lista cada linha forjada na sua categoria, com ids e sem PHI, e sai com código 1', function () {
    $legit  = auditRefLegitEntries($this);
    $forged = auditRefForgedEntries($this);

    $command = $this->artisan('financial:audit-cash-references')
        ->expectsOutputToContain(__('financial_cash_flow.audit_references.found', ['count' => count($forged)]));

    foreach ($forged as $issue => $entry) {
        $command->expectsOutputToContain(__("financial_cash_flow.audit_references.issues.{$issue}"))
            ->expectsOutputToContain("{$entry->id} | {$this->entity->id}");
    }

    foreach ($legit as $entry) {
        $command->doesntExpectOutputToContain($entry->id);
    }

    $command->doesntExpectOutputToContain('Maria da Silva')
        ->doesntExpectOutputToContain('123.456.789-00')
        ->assertExitCode(1);
});

it('classifica cada tipo de linha forjada na categoria certa', function (string $issue) {
    auditRefLegitEntries($this);
    $entry = auditRefForgers($this)[$issue]();

    // O cabeçalho da amostra só sai para categoria com contagem > 0.
    $this->artisan('financial:audit-cash-references')
        ->expectsOutputToContain(__('financial_cash_flow.audit_references.sample', [
            'issue' => __("financial_cash_flow.audit_references.issues.{$issue}"),
            'limit' => 50,
        ]))
        ->expectsOutputToContain("{$entry->id} | {$this->entity->id}")
        ->expectsOutputToContain(__('financial_cash_flow.audit_references.found', ['count' => 1]))
        ->assertExitCode(1);
})->with(['unknown_type', 'incomplete', 'missing_target', 'cross_entity', 'claim_mismatch']);

it('é somente leitura: não altera nenhuma linha', function () {
    auditRefLegitEntries($this);
    auditRefForgedEntries($this);
    $before = auditRefSnapshot();

    $this->artisan('financial:audit-cash-references', ['--with-trashed' => true])->assertExitCode(1);

    expect(auditRefSnapshot())->toBe($before);
});

it('filtra por clínica com --entity', function () {
    auditRefForgedEntries($this);

    $this->artisan('financial:audit-cash-references', ['--entity' => $this->otherEntity->id])
        ->expectsOutputToContain(__('financial_cash_flow.audit_references.none'))
        ->assertExitCode(0);

    $this->artisan('financial:audit-cash-references', ['--entity' => $this->entity->id])
        ->assertExitCode(1);
});

it('ignora excluídos por padrão e os inclui com --with-trashed', function () {
    $entry = auditRefEntry($this->entity, [
        'reference_type' => CashEntryReferenceType::Schedule->value,
        'reference_id'   => $this->otherSchedule->id,
    ]);
    $entry->delete();

    $this->artisan('financial:audit-cash-references')->assertExitCode(0);

    $this->artisan('financial:audit-cash-references', ['--with-trashed' => true])
        ->expectsOutputToContain("{$entry->id} | {$this->entity->id}")
        ->assertExitCode(1);
});

it('recusa opções inválidas', function () {
    $this->artisan('financial:audit-cash-references', ['--entity' => 'nao-e-uuid'])
        ->expectsOutputToContain(__('financial_cash_flow.audit_references.invalid_entity'))
        ->assertExitCode(2);

    $this->artisan('financial:audit-cash-references', ['--limit' => '0'])->assertExitCode(2);
});
