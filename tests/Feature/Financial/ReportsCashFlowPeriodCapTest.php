<?php

declare(strict_types=1);

use App\Http\Controllers\Financial\FinancialReportsController;
use App\Models\{Entity, FinancialCashEntry};
use Barryvdh\Snappy\Facades\SnappyPdf;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;

/*
 * Teto do período do relatório de fluxo de caixa
 * (FinancialReportsController::CASH_FLOW_MAX_PERIOD_DAYS): período maior é
 * recortado no servidor mantendo a data final, com aviso na tela (nunca erro
 * 500), e o MESMO recorte vale para CSV/XLSX/PDF — nome do arquivo, conteúdo
 * e evento de auditoria.
 */

const REPORTS_CF_CAP_TO          = '2026-09-27';
const REPORTS_CF_CAP_CAPPED_FROM = '2025-09-27';

beforeEach(function () {
    $this->entity = Entity::factory()->create(['is_client' => true, 'active' => true]);
    actingAsFinancialEntityUser($this->entity);
});

function reportsCfCapEntry(Entity $entity, string $date, string $description, float $amount = 100): FinancialCashEntry
{
    return FinancialCashEntry::query()->create([
        'entity_id'   => $entity->id,
        'entry_date'  => $date,
        'description' => $description,
        'type'        => 'income',
        'status'      => 'paid',
        'amount'      => $amount,
        'active'      => true,
    ]);
}

/** sheet1.xml de um .xlsx em memória. */
function reportsCfCapXlsxSheet(string $binary): string
{
    $tmp = tempnam(sys_get_temp_dir(), 'cf_cap_xlsx_');
    file_put_contents($tmp, $binary);

    $zip = new ZipArchive();
    $zip->open($tmp);
    $sheet = (string) $zip->getFromName('xl/worksheets/sheet1.xml');
    $zip->close();
    @unlink($tmp);

    return $sheet;
}

it('o teto é de 366 dias contados até a data final', function () {
    expect(FinancialReportsController::CASH_FLOW_MAX_PERIOD_DAYS)->toBe(366)
        ->and(CarbonImmutable::parse(REPORTS_CF_CAP_TO)->subDays(FinancialReportsController::CASH_FLOW_MAX_PERIOD_DAYS - 1)->toDateString())
        ->toBe(REPORTS_CF_CAP_CAPPED_FROM);
});

it('período acima do teto é recortado mantendo o fim, com o período pedido para o aviso da tela', function () {
    reportsCfCapEntry($this->entity, '2024-03-10', 'Antigo', 500);
    $first  = reportsCfCapEntry($this->entity, REPORTS_CF_CAP_CAPPED_FROM, 'Primeiro dia do recorte', 40);
    $recent = reportsCfCapEntry($this->entity, '2026-09-01', 'Recente', 60);

    $response = $this->get(route('panel.financial.reports.cash-flow', ['from' => '2024-01-01', 'to' => REPORTS_CF_CAP_TO]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('filters.from', REPORTS_CF_CAP_CAPPED_FROM)
            ->where('filters.to', REPORTS_CF_CAP_TO)
            ->where('period_capped.requested_from', '2024-01-01')
            ->where('period_capped.requested_to', REPORTS_CF_CAP_TO)
            ->where('period_capped.max_days', 366)
            ->where('t.cashflow.period_capped', __('financial_reports.cashflow.period_capped')));

    $props = $response->viewData('page')['props'];

    expect(collect($props['entries']['data'])->pluck('id')->all())->toBe([$first->id, $recent->id])
        ->and($props['summary']['income'])->toBe(100.0)
        ->and($props['overview']['entries_count'])->toBe(2)
        ->and(array_column($props['byDay'], 'day'))->toBe([REPORTS_CF_CAP_CAPPED_FROM, '2026-09-01'])
        ->and($props['entries']['total'])->toBe(2);
});

it('período no limite exato não é recortado; um dia a mais é', function () {
    $this->get(route('panel.financial.reports.cash-flow', ['from' => REPORTS_CF_CAP_CAPPED_FROM, 'to' => REPORTS_CF_CAP_TO]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('filters.from', REPORTS_CF_CAP_CAPPED_FROM)
            ->where('period_capped', null));

    $this->get(route('panel.financial.reports.cash-flow', ['from' => '2025-09-26', 'to' => REPORTS_CF_CAP_TO]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('filters.from', REPORTS_CF_CAP_CAPPED_FROM)
            ->where('period_capped.requested_from', '2025-09-26'));
});

it('períodos extremos nunca viram erro 500', function (string $from, string $to, string $expectedFrom) {
    $this->get(route('panel.financial.reports.cash-flow', ['from' => $from, 'to' => $to]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('filters.from', $expectedFrom)
            ->where('filters.to', $to));
})->with([
    'século inteiro' => ['1900-01-01', '2026-09-27', '2025-09-27'],
    'até 9999'       => ['1900-01-01', '9999-12-31', '9998-12-31'],
    'fim bissexto'   => ['2020-01-01', '2024-02-29', '2023-03-01'],
]);

it('aviso do recorte sai no idioma do usuário', function () {
    $this->withSession(['locale' => 'en'])
        ->get(route('panel.financial.reports.cash-flow', ['from' => '2024-01-01', 'to' => REPORTS_CF_CAP_TO]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('t.cashflow.period_capped', __('financial_reports.cashflow.period_capped', [], 'en'))
            ->where('period_capped.max_days', 366));
});

it('exportação usa o mesmo teto: nome do arquivo, conteúdo e auditoria com o período recortado', function (string $format) {
    reportsCfCapEntry($this->entity, '2024-03-10', 'Antigo', 500);
    reportsCfCapEntry($this->entity, '2026-09-01', 'Recente', 60);

    $response = $this->get(route('panel.financial.reports.cash-flow.export', ['from' => '2024-01-01', 'to' => REPORTS_CF_CAP_TO, 'format' => $format]))
        ->assertOk()
        ->assertHeader('Content-Disposition', 'attachment; filename="fluxo_caixa_' . REPORTS_CF_CAP_CAPPED_FROM . '_' . REPORTS_CF_CAP_TO . ".{$format}\"");

    $content = $format === 'xlsx' ? reportsCfCapXlsxSheet($response->getContent()) : $response->getContent();

    expect($content)->toContain('Recente')
        ->and($content)->not->toContain('Antigo');

    $log = DB::table('audit_logs')->where('event', 'financial.report.export')->sole();

    expect(json_decode((string) $log->new_values, true))->toMatchArray([
        'report' => 'cash_flow', 'format' => $format, 'from' => REPORTS_CF_CAP_CAPPED_FROM, 'to' => REPORTS_CF_CAP_TO, 'rows' => 1,
    ]);
})->with(['csv', 'xlsx']);

it('PDF recebe só os lançamentos e o resumo do período recortado', function () {
    reportsCfCapEntry($this->entity, '2024-03-10', 'Antigo', 500);
    reportsCfCapEntry($this->entity, '2026-09-01', 'Recente', 60);

    $pdf = Mockery::mock();
    $pdf->shouldReceive('setPaper')->andReturnSelf();
    $pdf->shouldReceive('setOrientation')->andReturnSelf();
    $pdf->shouldReceive('download')->andReturn(response('%PDF-fake', 200, ['Content-Type' => 'application/pdf']));

    SnappyPdf::shouldReceive('loadView')
        ->once()
        ->withArgs(fn (string $view, array $data): bool => $view === 'pdf.financial_cashflow'
            && $data['from'] === REPORTS_CF_CAP_CAPPED_FROM
            && $data['to'] === REPORTS_CF_CAP_TO
            && $data['entries']->pluck('description')->all() === ['Recente']
            && $data['summary']['income'] === 60.0)
        ->andReturn($pdf);

    $this->get(route('panel.financial.reports.cash-flow.export', ['from' => '2024-01-01', 'to' => REPORTS_CF_CAP_TO, 'format' => 'pdf']))
        ->assertOk();

    expect(DB::table('audit_logs')->where('event', 'financial.report.export')->count())->toBe(1);
});
