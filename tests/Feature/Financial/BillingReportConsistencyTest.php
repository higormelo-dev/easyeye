<?php

declare(strict_types=1);

use App\Enums\BillingClaimStatus;
use App\Models\{BillingClaim, Covenant, Entity};
use App\Services\Financial\{BillingReportService, ClinicBiService, CovenantReportService};
use Illuminate\Support\Facades\DB;

/*
 * Fonte única do faturamento por convênio (BillingReportService, fase 4):
 * para a mesma clínica e período, Dashboard gerencial == relatório de
 * convênios em Faturado, Recebido e Glosado — por convênio e nos totais —,
 * inclusive com convênio excluído (inativo), "Sem convênio" e centavos.
 * Outra clínica, rascunho, cancelada e fora do período não entram em nenhum.
 */

const BILLING_REPORT_FROM = '2026-08-01';
const BILLING_REPORT_TO   = '2026-08-31';

beforeEach(function () {
    $this->entity = Entity::factory()->create(['is_client' => true, 'active' => true]);
    actingAsFinancialEntityUser($this->entity);
});

function billingReportClaim(
    Entity $entity,
    ?Covenant $covenant,
    BillingClaimStatus $status,
    float $amount,
    float $paid = 0,
    float $glosa = 0,
    string $date = '2026-08-10',
): BillingClaim {
    return BillingClaim::query()->create([
        'entity_id'       => $entity->id,
        'covenant_id'     => $covenant?->id,
        'status'          => $status->value,
        'attendance_date' => $date,
        'amount'          => $amount,
        'paid_amount'     => $paid,
        'glosa_amount'    => $glosa,
        'quantity'        => 1,
        'unit_price'      => $amount,
    ]);
}

function billingReportCovenant(?Entity $entity, string $name): Covenant
{
    return Covenant::factory()->create(['entity_id' => $entity?->id, 'active' => true, 'name' => $name]);
}

/** [summary do BI, props do relatório de convênios] no mesmo período. */
function billingReportScreens($testCase): array
{
    $query = ['from' => BILLING_REPORT_FROM, 'to' => BILLING_REPORT_TO];

    return [
        $testCase->get(route('panel.financial.bi.index', $query))->assertOk()->viewData('page')['props']['summary'],
        $testCase->get(route('panel.financial.reports.covenants', $query))->assertOk()->viewData('page')['props'],
    ];
}

/** BI × relatório: mesmas linhas (pela chave covenant_id, na mesma ordem) e mesmos totais. */
function billingReportExpectSame(array $bi, array $report): void
{
    $chart = collect($bi['by_covenant_chart'])->keyBy('covenant_id');
    $rows  = collect($report['byCovenant'])->take(ClinicBiService::COVENANT_CHART_LIMIT)->keyBy('covenant_id');

    expect($chart->keys()->all())->toBe($rows->keys()->all());

    foreach ($rows as $covenantId => $row) {
        expect($chart[$covenantId]['value'])->toBe($row['amount'])
            ->and($chart[$covenantId]['paid'])->toBe($row['paid'])
            ->and($chart[$covenantId]['denied'])->toBe($row['denied'])
            ->and($chart[$covenantId]['inactive'])->toBe($row['inactive']);
    }

    expect($bi['kpis']['total_billed'])->toBe($report['summary']['total_amount'])
        ->and($bi['kpis']['total_paid'])->toBe($report['summary']['total_paid'])
        ->and($bi['kpis']['total_glosa'])->toBe($report['summary']['total_denied']);
}

it('BI e relatório batem por convênio e nos totais, com centavos exatos, convênio global e convênio excluído', function () {
    $unimed = billingReportCovenant($this->entity, 'UNIMED');
    $global = billingReportCovenant(null, 'GLOBAL SAUDE');
    $old    = billingReportCovenant($this->entity, 'UNIMED');

    billingReportClaim($this->entity, $unimed, BillingClaimStatus::Submitted, 100.10);
    billingReportClaim($this->entity, $unimed, BillingClaimStatus::Paid, 200.20, paid: 180.15, glosa: 20.05);
    // Glosada com paid_amount residual: não conta como recebida.
    billingReportClaim($this->entity, $unimed, BillingClaimStatus::Denied, 50.33, paid: 10, glosa: 50.33);
    billingReportClaim($this->entity, $unimed, BillingClaimStatus::Draft, 999);
    billingReportClaim($this->entity, $unimed, BillingClaimStatus::Cancelled, 888, glosa: 888);
    // 0,10 + 0,20: em float somado no PHP daria 0.30000000000000004.
    billingReportClaim($this->entity, $global, BillingClaimStatus::Paid, 0.10, paid: 0.10);
    billingReportClaim($this->entity, $global, BillingClaimStatus::Paid, 0.20, paid: 0.20);
    billingReportClaim($this->entity, $old, BillingClaimStatus::Paid, 70.07, paid: 70.07);
    $old->delete(); // homônimo excluído: linha própria, marcada inativa

    // Fora do período e de outra clínica (no convênio global compartilhado).
    billingReportClaim($this->entity, $unimed, BillingClaimStatus::Paid, 5000, paid: 5000, date: '2026-07-31');
    $other = Entity::factory()->create(['is_client' => true, 'active' => true]);
    billingReportClaim($other, $global, BillingClaimStatus::Paid, 7000, paid: 7000);

    [$bi, $report] = billingReportScreens($this);

    billingReportExpectSame($bi, $report);

    $rows = collect($report['byCovenant'])->keyBy('covenant_id');

    expect(array_column($report['byCovenant'], 'covenant_id'))->toBe([$unimed->id, $old->id, $global->id])
        ->and($rows[$unimed->id])->toMatchArray(['amount' => 350.63, 'paid' => 180.15, 'denied' => 70.38, 'claims' => 3, 'inactive' => false])
        ->and($rows[$old->id])->toMatchArray(['covenant' => 'UNIMED', 'amount' => 70.07, 'paid' => 70.07, 'inactive' => true])
        ->and($rows[$global->id])->toMatchArray(['amount' => 0.3, 'paid' => 0.3, 'denied' => 0.0, 'claims' => 2])
        ->and($report['summary'])->toMatchArray(['total_claims' => 6, 'total_amount' => 421.0, 'total_paid' => 250.52, 'total_denied' => 70.38])
        // Rótulos no idioma, aplicados depois do cache do BI.
        ->and(array_column($bi['by_covenant_chart'], 'label'))->toBe(['UNIMED', 'UNIMED (inativo)', 'GLOBAL SAUDE'])
        // Ticket médio e taxa de recebimento sobre os mesmos totais (4 guias pagas).
        ->and($bi['kpis']['ticket_medio'])->toBe(62.63)
        ->and($bi['kpis']['receipt_rate'])->toBe(59.5);
});

it('"Sem convênio" usa a mesma chave (\'\') e os mesmos valores nas duas telas', function () {
    if (DB::getDriverName() !== 'pgsql') {
        $this->markTestSkipped('Simulação de guia legada sem convênio depende de DDL transacional (PostgreSQL).');
    }

    // Schema atual exige covenant_id: a guia legada é simulada relaxando a
    // coluna DENTRO da transação do teste (desfeito no rollback).
    DB::statement('ALTER TABLE billing_claims ALTER COLUMN covenant_id DROP NOT NULL');

    $unimed = billingReportCovenant($this->entity, 'UNIMED');

    billingReportClaim($this->entity, null, BillingClaimStatus::Paid, 40.05, paid: 30.02, glosa: 10.03);
    billingReportClaim($this->entity, null, BillingClaimStatus::Submitted, 59.95);
    billingReportClaim($this->entity, $unimed, BillingClaimStatus::Submitted, 100);

    [$bi, $report] = billingReportScreens($this);

    billingReportExpectSame($bi, $report);

    $none = collect($bi['by_covenant_chart'])->firstWhere('covenant_id', '');

    // Empate de faturado (100 × 100): convênio com nome primeiro, nas duas telas.
    expect(array_column($report['byCovenant'], 'covenant_id'))->toBe([$unimed->id, ''])
        ->and($none)->toMatchArray(['label' => 'Sem convênio', 'value' => 100.0, 'paid' => 30.02, 'denied' => 10.03, 'inactive' => false])
        ->and(collect($report['byCovenant'])->firstWhere('covenant_id', ''))->toMatchArray(['covenant' => 'Sem convênio', 'amount' => 100.0, 'open' => 59.95]);
});

it('a lista do BI são as primeiras linhas do relatório (desempate por nome), limitadas ao top do BI', function () {
    $amounts = ['H' => 100, 'A' => 800, 'B' => 700, 'C' => 600, 'D' => 500, 'E' => 400, 'GAMA' => 300, 'BETA' => 300];

    foreach ($amounts as $name => $amount) {
        billingReportClaim($this->entity, billingReportCovenant($this->entity, $name), BillingClaimStatus::Submitted, $amount);
    }

    [$bi, $report] = billingReportScreens($this);

    billingReportExpectSame($bi, $report);

    expect($report['byCovenant'])->toHaveCount(8)
        ->and($bi['by_covenant_chart'])->toHaveCount(ClinicBiService::COVENANT_CHART_LIMIT)
        ->and(array_column($bi['by_covenant_chart'], 'label'))->toBe(['A', 'B', 'C', 'D', 'E', 'BETA'])
        // Totais continuam sobre TODOS os convênios, não só o top 6.
        ->and($bi['kpis']['total_billed'])->toBe(3700.0);
});

it('uma clínica não vê o faturamento de outra em nenhuma das duas telas', function () {
    $global = billingReportCovenant(null, 'GLOBAL');
    $other  = Entity::factory()->create(['is_client' => true, 'active' => true]);

    billingReportClaim($other, $global, BillingClaimStatus::Paid, 9000, paid: 9000, glosa: 100);
    billingReportClaim($this->entity, $global, BillingClaimStatus::Submitted, 100);

    [$bi, $report] = billingReportScreens($this);

    billingReportExpectSame($bi, $report);

    expect($report['byCovenant'])->toHaveCount(1)
        ->and($report['summary'])->toMatchArray(['total_amount' => 100.0, 'total_paid' => 0.0, 'total_denied' => 0.0])
        ->and($bi['kpis'])->toMatchArray(['total_billed' => 100.0, 'total_paid' => 0.0, 'total_glosa' => 0.0]);
});

it('o serviço único é a regra das duas telas: guias válidas, "pago" só de guia paga e totais = soma das linhas', function () {
    $unimed = billingReportCovenant($this->entity, 'UNIMED');

    billingReportClaim($this->entity, $unimed, BillingClaimStatus::Paid, 100, paid: 90, glosa: 10);
    billingReportClaim($this->entity, $unimed, BillingClaimStatus::Denied, 50, paid: 5, glosa: 50);
    billingReportClaim($this->entity, $unimed, BillingClaimStatus::Draft, 70);
    billingReportClaim($this->entity, $unimed, BillingClaimStatus::Cancelled, 30);
    billingReportClaim($this->entity, $unimed, BillingClaimStatus::Submitted, 25);

    $service = app(BillingReportService::class);
    $rows    = $service->byCovenant((string) $this->entity->id, BILLING_REPORT_FROM, BILLING_REPORT_TO);

    expect(CovenantReportService::NOT_BILLED_STATUSES)->toBe(BillingReportService::NOT_BILLED_STATUSES)
        ->and($rows)->toBe([[
            'covenant_id'   => (string) $unimed->id,
            'covenant_name' => 'UNIMED',
            'inactive'      => false,
            'claims'        => 3,
            'paid_claims'   => 1,
            'amount'        => 175.0,
            'paid'          => 90.0,
            'denied'        => 60.0,
            'open'          => 25.0,
        ]])
        ->and($service->totals($rows))->toBe([
            'claims' => 3, 'paid_claims' => 1, 'amount' => 175.0, 'paid' => 90.0, 'denied' => 60.0, 'open' => 25.0,
        ]);
});
