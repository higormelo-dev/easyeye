<?php

declare(strict_types=1);

use App\Enums\BillingClaimStatus;
use App\Models\{BillingClaim, Covenant, Entity};
use Inertia\Testing\AssertableInertia as Assert;

/*
 * Relatório de faturamento por convênio alinhado ao Dashboard gerencial
 * (decisão do produto): rascunho e cancelada fora de "Guias"/"Total
 * faturado" e "Recebido" = paid_amount só das guias pagas, no card E na
 * coluna por convênio.
 */

beforeEach(function () {
    $this->entity   = Entity::factory()->create(['is_client' => true, 'active' => true]);
    $this->covenant = Covenant::factory()->create(['entity_id' => $this->entity->id, 'active' => true, 'name' => 'UNIMED']);
    actingAsFinancialEntityUser($this->entity);
});

function reportsCovenantsClaim(Entity $entity, Covenant $covenant, BillingClaimStatus $status, float $amount, float $paid = 0, float $glosa = 0): BillingClaim
{
    return BillingClaim::query()->create([
        'entity_id'       => $entity->id,
        'covenant_id'     => $covenant->id,
        'status'          => $status->value,
        'attendance_date' => now()->toDateString(),
        'amount'          => $amount,
        'paid_amount'     => $paid,
        'glosa_amount'    => $glosa,
        'quantity'        => 1,
        'unit_price'      => $amount,
    ]);
}

function reportsCovenantsSeed(Entity $entity, Covenant $covenant): void
{
    reportsCovenantsClaim($entity, $covenant, BillingClaimStatus::Submitted, 100);
    reportsCovenantsClaim($entity, $covenant, BillingClaimStatus::Paid, 200, paid: 180, glosa: 20);
    // Glosada com paid_amount residual: não conta como recebida (regra do BI).
    reportsCovenantsClaim($entity, $covenant, BillingClaimStatus::Denied, 50, paid: 10, glosa: 50);
    reportsCovenantsClaim($entity, $covenant, BillingClaimStatus::Draft, 1000);
    reportsCovenantsClaim($entity, $covenant, BillingClaimStatus::Cancelled, 500, glosa: 500);
}

it('exclui guias em rascunho e canceladas e usa a regra de "Recebido" do BI no card e na tabela', function () {
    reportsCovenantsSeed($this->entity, $this->covenant);

    $props = $this->get(route('panel.financial.reports.covenants'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Panel/Financial/Reports/Covenants')
            ->has('summary.total_claims')
            ->has('summary.total_amount')
            ->has('summary.total_paid')
            ->has('summary.total_denied')
            ->has('byCovenant', 1)
            ->has('routes.export')
            ->where('export_formats', ['csv', 'xlsx'])
            ->has('t.covenants.period_basis'))
        ->viewData('page')['props'];

    expect($props['summary']['total_claims'])->toBe(3)
        ->and($props['summary']['total_amount'])->toEqual(350.0)
        ->and($props['summary']['total_paid'])->toEqual(180.0)
        ->and($props['summary']['total_denied'])->toEqual(70.0);

    $row = $props['byCovenant'][0];

    expect($row['covenant'])->toBe('UNIMED')
        ->and($row['claims'])->toBe(3)
        ->and($row['amount'])->toEqual(350.0)
        // Antes: 190 (somava o paid_amount da guia glosada) e não batia com o card.
        ->and($row['paid'])->toEqual(180.0)
        ->and($row['denied'])->toEqual(70.0);
});

it('bate com o Dashboard gerencial no mesmo período (faturado, recebido e glosado)', function () {
    reportsCovenantsSeed($this->entity, $this->covenant);

    $report = $this->get(route('panel.financial.reports.covenants'))->viewData('page')['props']['summary'];
    $bi     = $this->get(route('panel.financial.bi.index'))->viewData('page')['props']['summary']['kpis'];

    expect($report['total_amount'])->toEqual($bi['total_billed'])
        ->and($report['total_paid'])->toEqual($bi['total_paid'])
        ->and($report['total_denied'])->toEqual($bi['total_glosa']);
});

it('convênio excluído mantém o nome e o faturamento na própria linha (marcado inativo), sem se fundir com o homônimo', function () {
    // Antes: a relação ignorava o convênio excluído (soft delete) e a linha
    // virava "Sem convênio"; e o agrupamento pelo nome fundia homônimos.
    reportsCovenantsClaim($this->entity, $this->covenant, BillingClaimStatus::Paid, 300, paid: 300);
    $this->covenant->delete(); // soft delete (Configurações > Convênios)

    $recreated = Covenant::factory()->create(['entity_id' => $this->entity->id, 'active' => true, 'name' => 'UNIMED']);
    reportsCovenantsClaim($this->entity, $recreated, BillingClaimStatus::Submitted, 100);

    $props = $this->get(route('panel.financial.reports.covenants'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('byCovenant', 2)
            ->has('t.covenants.inactive_badge'))
        ->viewData('page')['props'];

    $rows = collect($props['byCovenant'])->keyBy('covenant_id');

    expect($rows[$this->covenant->id])->toMatchArray(['covenant' => 'UNIMED', 'inactive' => true, 'claims' => 1])
        ->and($rows[$this->covenant->id]['amount'])->toEqual(300.0)
        ->and($rows[$this->covenant->id]['paid'])->toEqual(300.0)
        ->and($rows[$recreated->id])->toMatchArray(['covenant' => 'UNIMED', 'inactive' => false, 'claims' => 1])
        ->and($rows[$recreated->id]['amount'])->toEqual(100.0)
        ->and(collect($props['byCovenant'])->pluck('covenant'))->not->toContain('Sem convênio');
});

it('período inválido cai no mês atual e invertido é trocado (sem erro 500)', function () {
    $this->get(route('panel.financial.reports.covenants', ['from' => '2026-13-45', 'to' => 'x']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('filters.from', now()->startOfMonth()->toDateString())
            ->where('filters.to', now()->toDateString()));

    $this->get(route('panel.financial.reports.covenants', ['from' => '2026-09-20', 'to' => '2026-09-01']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('filters.from', '2026-09-01')
            ->where('filters.to', '2026-09-20'));
});

it('não mistura guias de outra clínica', function () {
    $other         = Entity::factory()->create(['is_client' => true, 'active' => true]);
    $otherCovenant = Covenant::factory()->create(['entity_id' => $other->id, 'active' => true]);
    reportsCovenantsClaim($other, $otherCovenant, BillingClaimStatus::Paid, 9000, paid: 9000);
    reportsCovenantsClaim($this->entity, $this->covenant, BillingClaimStatus::Submitted, 100);

    $this->get(route('panel.financial.reports.covenants'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('summary.total_claims', 1)
            ->has('byCovenant', 1));
});
