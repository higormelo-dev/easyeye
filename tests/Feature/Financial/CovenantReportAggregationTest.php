<?php

declare(strict_types=1);

use App\Enums\{BillingClaimStatus, ClientRule};
use App\Models\{BillingClaim, Covenant, Entity, User};
use App\Services\Financial\CovenantReportService;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;

/*
 * Relatório de convênios agregado no banco (CovenantReportService::byCovenant,
 * GROUP BY covenant_id): os números têm de ser os MESMOS da agregação em
 * memória que existia antes (e do Dashboard gerencial), inclusive "Sem
 * convênio" (chave '' do BI) e convênio excluído; colunas novas Em aberto,
 * % Glosa (com alerta acima do limiar) e % Recebido; isolamento por clínica.
 */

beforeEach(function () {
    $this->entity   = Entity::factory()->create(['is_client' => true, 'active' => true]);
    $this->covenant = Covenant::factory()->create(['entity_id' => $this->entity->id, 'active' => true, 'name' => 'UNIMED']);
    actingAsFinancialEntityUser($this->entity);
});

function covenantReportClaim(Entity $entity, ?Covenant $covenant, BillingClaimStatus $status, float $amount, array $attrs = []): BillingClaim
{
    return BillingClaim::query()->create(array_merge([
        'entity_id'       => $entity->id,
        'covenant_id'     => $covenant?->id,
        'status'          => $status->value,
        'attendance_date' => now()->toDateString(),
        'amount'          => $amount,
        'paid_amount'     => 0,
        'glosa_amount'    => 0,
        'quantity'        => 1,
        'unit_price'      => $amount,
    ], $attrs));
}

/**
 * A agregação em memória que o controller fazia antes (cópia fiel), para
 * provar que o GROUP BY devolve os mesmos números.
 */
function covenantReportLegacyRows(string $entityId, string $from, string $to): array
{
    $claims = BillingClaim::query()
        ->with(['covenant' => fn ($query) => $query->withTrashed()->select('id', 'name', 'deleted_at')])
        ->where('entity_id', $entityId)
        ->whereBetween('attendance_date', [$from, $to])
        ->whereNotIn('status', ['draft', 'cancelled'])
        ->whereNull('deleted_at')
        ->get(['id', 'covenant_id', 'status', 'attendance_date', 'amount', 'paid_amount', 'glosa_amount']);

    return $claims
        ->groupBy(fn ($claim) => (string) $claim->covenant_id)
        ->map(fn ($group, $covenantId) => [
            'covenant_id' => (string) $covenantId,
            'covenant'    => $group->first()->covenant?->name ?? __('financial_reports.no_covenant'),
            'inactive'    => (bool) $group->first()->covenant?->trashed(),
            'claims'      => $group->count(),
            'amount'      => (float) $group->sum('amount'),
            'paid'        => (float) $group->filter(fn ($claim) => $claim->status === BillingClaimStatus::Paid)->sum('paid_amount'),
            'denied'      => (float) $group->sum('glosa_amount'),
        ])
        ->all();
}

function covenantReportPageProps($testCase, array $query = []): array
{
    return $testCase->get(route('panel.financial.reports.covenants', $query))
        ->assertOk()
        ->viewData('page')['props'];
}

function covenantReportExpectSameAsLegacy(array $rows, array $legacy): void
{
    expect(collect($rows)->pluck('covenant_id')->sort()->values()->all())
        ->toBe(collect(array_keys($legacy))->map(fn ($key) => (string) $key)->sort()->values()->all());

    foreach ($rows as $row) {
        $old = $legacy[$row['covenant_id']];

        expect($row['covenant'])->toBe($old['covenant'])
            ->and($row['inactive'])->toBe($old['inactive'])
            ->and($row['claims'])->toBe($old['claims'])
            ->and($row['amount'])->toEqualWithDelta($old['amount'], 0.001)
            ->and($row['paid'])->toEqualWithDelta($old['paid'], 0.001)
            ->and($row['denied'])->toEqualWithDelta($old['denied'], 0.001);
    }
}

it('agregado no banco devolve os mesmos números da agregação em memória de antes e do BI (com centavos e convênio inativo)', function () {
    $global   = Covenant::factory()->create(['entity_id' => null, 'active' => true, 'name' => 'GLOBAL SAUDE']);
    $inactive = Covenant::factory()->create(['entity_id' => $this->entity->id, 'active' => true, 'name' => 'UNIMED']);

    covenantReportClaim($this->entity, $this->covenant, BillingClaimStatus::Submitted, 100.10);
    covenantReportClaim($this->entity, $this->covenant, BillingClaimStatus::Paid, 200.20, ['paid_amount' => 180.15, 'glosa_amount' => 20.05]);
    covenantReportClaim($this->entity, $this->covenant, BillingClaimStatus::Denied, 50.33, ['paid_amount' => 10, 'glosa_amount' => 50.33]);
    covenantReportClaim($this->entity, $this->covenant, BillingClaimStatus::Draft, 999);
    covenantReportClaim($this->entity, $this->covenant, BillingClaimStatus::Cancelled, 888, ['glosa_amount' => 888]);
    covenantReportClaim($this->entity, $global, BillingClaimStatus::Paid, 0.3, ['paid_amount' => 0.3]);
    covenantReportClaim($this->entity, $global, BillingClaimStatus::Submitted, 0.1);
    covenantReportClaim($this->entity, $inactive, BillingClaimStatus::Paid, 70.07, ['paid_amount' => 70.07]);
    $inactive->delete(); // homônimo excluído: linha própria, marcada inativa

    // Fora do período e de outra clínica (mesmo convênio global): não entram.
    covenantReportClaim($this->entity, $this->covenant, BillingClaimStatus::Paid, 5000, ['attendance_date' => now()->subMonths(2)->toDateString(), 'paid_amount' => 5000]);
    $other = Entity::factory()->create(['is_client' => true, 'active' => true]);
    covenantReportClaim($other, $global, BillingClaimStatus::Paid, 7000, ['paid_amount' => 7000]);

    $from = now()->startOfMonth()->toDateString();
    $to   = now()->toDateString();

    $props = covenantReportPageProps($this);

    covenantReportExpectSameAsLegacy($props['byCovenant'], covenantReportLegacyRows((string) $this->entity->id, $from, $to));

    $bi = $this->get(route('panel.financial.bi.index'))->viewData('page')['props']['summary'];

    expect($props['summary']['total_claims'])->toBe(6)
        ->and($props['summary']['total_amount'])->toEqualWithDelta($bi['kpis']['total_billed'], 0.001)
        ->and($props['summary']['total_paid'])->toEqualWithDelta($bi['kpis']['total_paid'], 0.001)
        ->and($props['summary']['total_denied'])->toEqualWithDelta($bi['kpis']['total_glosa'], 0.001)
        ->and($props['summary']['total_amount'])->toBe(421.1)
        ->and($props['summary']['total_paid'])->toBe(250.52)
        ->and($props['summary']['total_denied'])->toBe(70.38);

    // Mesmo faturado por convênio que o gráfico do BI (agrupado pelo id).
    $chart = collect($bi['by_covenant_chart'])->pluck('value')->sort()->values()->all();
    $rows  = collect($props['byCovenant'])->pluck('amount')->sort()->values()->all();

    expect(count($rows))->toBe(count($chart));

    foreach ($rows as $index => $value) {
        expect($value)->toEqualWithDelta($chart[$index], 0.001);
    }
});

it('"Sem convênio" usa a chave do BI (\'\') e soma igual à agregação de antes', function () {
    if (DB::getDriverName() !== 'pgsql') {
        $this->markTestSkipped('Simulação de guia legada sem convênio depende de DDL transacional (PostgreSQL).');
    }

    // O schema atual exige covenant_id (NOT NULL): a guia legada sem convênio é
    // simulada relaxando a coluna DENTRO da transação do teste — DDL no
    // PostgreSQL é transacional e o RefreshDatabase desfaz no rollback.
    DB::statement('ALTER TABLE billing_claims ALTER COLUMN covenant_id DROP NOT NULL');

    covenantReportClaim($this->entity, null, BillingClaimStatus::Paid, 40, ['paid_amount' => 30, 'glosa_amount' => 10]);
    covenantReportClaim($this->entity, null, BillingClaimStatus::Submitted, 60);
    covenantReportClaim($this->entity, $this->covenant, BillingClaimStatus::Submitted, 100);

    $props = covenantReportPageProps($this);
    $rows  = collect($props['byCovenant'])->keyBy('covenant_id');

    covenantReportExpectSameAsLegacy($props['byCovenant'], covenantReportLegacyRows(
        (string) $this->entity->id,
        now()->startOfMonth()->toDateString(),
        now()->toDateString(),
    ));

    expect($rows)->toHaveKey('')
        ->and($rows['']['covenant'])->toBe('Sem convênio')
        ->and($rows['']['inactive'])->toBeFalse()
        ->and($rows['']['claims'])->toBe(2)
        ->and($rows['']['amount'])->toBe(100.0)
        ->and($rows['']['paid'])->toBe(30.0)
        ->and($rows['']['open'])->toBe(60.0);

    // O detalhe da linha "Sem convênio" usa covenant_id vazio (a mesma chave).
    $this->getJson(route('panel.financial.reports.covenants.claims', ['covenant_id' => '']))
        ->assertOk()
        ->assertJsonPath('meta.total', 2);

    // Mesmo rótulo e mesmo valor que o gráfico do BI dá para "Sem convênio".
    $chart = collect($this->get(route('panel.financial.bi.index'))->viewData('page')['props']['summary']['by_covenant_chart']);

    expect($chart->firstWhere('label', 'Sem convênio')['value'])->toEqual(100.0);
});

it('entrega Em aberto (guias enviadas), % Glosa com alerta acima do limiar e % Recebido', function () {
    // UNIMED: faturado 350, recebido 180, glosado 70 (20%), em aberto 100.
    covenantReportClaim($this->entity, $this->covenant, BillingClaimStatus::Submitted, 100);
    covenantReportClaim($this->entity, $this->covenant, BillingClaimStatus::Paid, 200, ['paid_amount' => 180, 'glosa_amount' => 20]);
    covenantReportClaim($this->entity, $this->covenant, BillingClaimStatus::Denied, 50, ['glosa_amount' => 50]);

    // Exatamente no limiar (10%): sem alerta — o selo é para ACIMA de 10%.
    $limit = Covenant::factory()->create(['entity_id' => $this->entity->id, 'active' => true, 'name' => 'LIMITE']);
    covenantReportClaim($this->entity, $limit, BillingClaimStatus::Paid, 100, ['paid_amount' => 90, 'glosa_amount' => 10]);

    // Faturado zerado: percentuais indefinidos (null → "—" na tela), sem alerta.
    $zero = Covenant::factory()->create(['entity_id' => $this->entity->id, 'active' => true, 'name' => 'ZERO']);
    covenantReportClaim($this->entity, $zero, BillingClaimStatus::Submitted, 0);

    $props = covenantReportPageProps($this);
    $rows  = collect($props['byCovenant'])->keyBy('covenant');

    expect($rows['UNIMED'])->toMatchArray([
        'claims'        => 3,
        'amount'        => 350.0,
        'paid'          => 180.0,
        'denied'        => 70.0,
        'open'          => 100.0,
        'glosa_rate'    => 20.0,
        'received_rate' => 51.4,
        'glosa_alert'   => true,
    ])
        ->and($rows['LIMITE'])->toMatchArray(['glosa_rate' => 10.0, 'received_rate' => 90.0, 'glosa_alert' => false, 'open' => 0.0])
        ->and($rows['ZERO'])->toMatchArray(['glosa_rate' => null, 'received_rate' => null, 'glosa_alert' => false])
        ->and($props['summary'])->toMatchArray([
            'total_claims'  => 5,
            'total_amount'  => 450.0,
            'total_paid'    => 270.0,
            'total_denied'  => 80.0,
            'total_open'    => 100.0,
            'glosa_rate'    => 17.8,
            'received_rate' => 60.0,
            'glosa_alert'   => true,
        ])
        ->and($props['glosa_alert_threshold'])->toEqual(CovenantReportService::GLOSA_ALERT_THRESHOLD)
        // Ordem padrão: maior faturado primeiro.
        ->and(collect($props['byCovenant'])->pluck('covenant')->all())->toBe(['UNIMED', 'LIMITE', 'ZERO']);
});

it('entrega período, hoje, rotas (inclusive o detalhe JSON) e textos do filtro compartilhado', function () {
    $this->get(route('panel.financial.reports.covenants'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Panel/Financial/Reports/Covenants')
            ->where('today', now()->toDateString())
            ->where('routes.claims', route('panel.financial.reports.covenants.claims'))
            ->has('t.shared.period.presets.last_month')
            ->has('t.covenants.col_glosa_rate')
            ->has('t.covenants.claims_count_one')
            ->has('summary.total_open')
            ->has('summary.glosa_rate'));
});

it('agregado de uma clínica não enxerga guias de outra, nem no convênio global compartilhado', function () {
    $global = Covenant::factory()->create(['entity_id' => null, 'active' => true, 'name' => 'GLOBAL']);
    $other  = Entity::factory()->create(['is_client' => true, 'active' => true]);

    covenantReportClaim($other, $global, BillingClaimStatus::Paid, 9000, ['paid_amount' => 9000]);
    covenantReportClaim($this->entity, $global, BillingClaimStatus::Submitted, 100);

    $props = covenantReportPageProps($this);

    expect($props['byCovenant'])->toHaveCount(1)
        ->and($props['byCovenant'][0])->toMatchArray(['covenant' => 'GLOBAL', 'claims' => 1, 'amount' => 100.0, 'paid' => 0.0])
        ->and($props['summary']['total_amount'])->toBe(100.0);
});

it('perfil sem permissão financeira (secretária) não abre o relatório nem o detalhe', function () {
    $user       = User::factory()->create();
    $entityUser = createEntityUser($this->entity, $user, ClientRule::Secretary->value);

    $this->withSession(panelSession($entityUser))->actingAs($user)
        ->get(route('panel.financial.reports.covenants'))
        ->assertForbidden();

    $this->withSession(panelSession($entityUser))->actingAs($user)
        ->getJson(route('panel.financial.reports.covenants.claims', ['covenant_id' => $this->covenant->id]))
        ->assertForbidden();
});
