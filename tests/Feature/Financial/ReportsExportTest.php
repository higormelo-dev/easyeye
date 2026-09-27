<?php

declare(strict_types=1);

use App\Enums\BillingClaimStatus;
use App\Models\{BillingClaim, Covenant, Entity, FinancialCashEntry, FinancialCategory, Patient, People};
use Barryvdh\Snappy\Facades\SnappyPdf;
use Illuminate\Support\Facades\DB;

/*
 * Exportações dos relatórios financeiros: CSV com BOM UTF-8 e cabeçalhos no
 * idioma do usuário, neutralização de fórmula (OWASP CSV injection) mantida,
 * convênios alinhado ao BI, XLSX para convênios, evento de auditoria por
 * exportação (sem nome de paciente) e PDF com falha voltando à tela com aviso.
 */

const REPORTS_EXPORT_BOM = "\xEF\xBB\xBF";

beforeEach(function () {
    $this->entity     = Entity::factory()->create(['is_client' => true, 'active' => true]);
    $this->covenant   = Covenant::factory()->create(['entity_id' => $this->entity->id, 'active' => true, 'name' => '-CMD']);
    $this->entityUser = actingAsFinancialEntityUser($this->entity);
});

function reportsExportClaim(Entity $entity, Covenant $covenant, BillingClaimStatus $status, float $amount, array $attrs = []): BillingClaim
{
    return BillingClaim::query()->create(array_merge([
        'entity_id'       => $entity->id,
        'covenant_id'     => $covenant->id,
        'status'          => $status->value,
        'attendance_date' => now()->toDateString(),
        'amount'          => $amount,
        'quantity'        => 1,
        'unit_price'      => $amount,
    ], $attrs));
}

function reportsExportPatient(Entity $entity, Covenant $covenant, string $name): Patient
{
    $person = People::factory()->create(['full_name' => $name]);

    return Patient::factory()->create([
        'entity_id'   => $entity->id,
        'person_id'   => $person->id,
        'covenant_id' => $covenant->id,
    ]);
}

/** Linhas do CSV (sem BOM), já separadas por ';'. */
function reportsExportCsvRows(string $body): array
{
    $lines = preg_split('/\r?\n/', trim(substr($body, strlen(REPORTS_EXPORT_BOM))));

    return array_map(fn (string $line) => str_getcsv($line, ';', '"', ''), $lines);
}

function reportsExportAuditRows(): array
{
    return DB::table('audit_logs')->where('event', 'financial.report.export')->get()->all();
}

it('CSV do fluxo de caixa sai com BOM UTF-8, cabeçalhos e vírgula decimal em pt_BR', function () {
    FinancialCashEntry::query()->create([
        'entity_id'   => $this->entity->id,
        'entry_date'  => now()->toDateString(),
        'description' => 'Consulta João',
        'type'        => 'income',
        'status'      => 'paid',
        'amount'      => 150.5,
        'active'      => true,
    ]);

    $response = $this->get(route('panel.financial.reports.cash-flow.export'))
        ->assertOk()
        ->assertHeader('Content-Type', 'text/csv; charset=UTF-8');

    $body = $response->getContent();
    $rows = reportsExportCsvRows($body);

    expect(str_starts_with($body, REPORTS_EXPORT_BOM))->toBeTrue()
        ->and($rows[0])->toBe(['Data', 'Código', 'Descrição', 'Tipo', 'Status', 'Categoria', 'Convênio', 'Valor'])
        ->and($rows[1][0])->toBe(now()->format('d/m/Y'))
        ->and($rows[1][2])->toBe('Consulta João')
        ->and($rows[1][3])->toBe('Receita')
        ->and($rows[1][4])->toBe('Pago')
        ->and($rows[1][5])->toBe('Sem categoria')
        ->and($rows[1][7])->toBe('150,50');
});

it('CSV sai com cabeçalhos e rótulos em inglês para usuário em inglês', function () {
    reportsExportClaim($this->entity, $this->covenant, BillingClaimStatus::Paid, 200, ['paid_amount' => 180, 'glosa_amount' => 20]);

    $body = $this->withSession(['locale' => 'en'])
        ->get(route('panel.financial.reports.covenants.export'))
        ->assertOk()
        ->getContent();

    $rows = reportsExportCsvRows($body);

    expect($rows[0])->toBe(['Attendance date', 'Claim', 'Insurer', 'Patient (code · initials)', 'Status', 'Amount', 'Denied', 'Received'])
        ->and($rows[1][4])->toBe('Paid')
        ->and($rows[1][5])->toBe('200.00')
        ->and($rows[1][7])->toBe('180.00');
});

it('neutraliza fórmula (= + - @) em texto livre no CSV sem mexer nos números', function () {
    $category = FinancialCategory::query()->create([
        'entity_id' => $this->entity->id,
        'name'      => '+CMD',
        'type'      => 'income',
        'active'    => true,
    ]);

    FinancialCashEntry::query()->create([
        'entity_id'   => $this->entity->id,
        'category_id' => $category->id,
        'covenant_id' => $this->covenant->id,
        'entry_date'  => now()->toDateString(),
        'description' => '=CMD',
        'type'        => 'income',
        'status'      => 'paid',
        'amount'      => 10,
        'active'      => true,
    ]);

    $cashFlow = reportsExportCsvRows($this->get(route('panel.financial.reports.cash-flow.export'))->assertOk()->getContent());

    expect($cashFlow[1][2])->toBe("'=CMD")
        ->and($cashFlow[1][5])->toBe("'+CMD")
        ->and($cashFlow[1][6])->toBe("'-CMD")
        ->and($cashFlow[1][7])->toBe('10,00');

    $patient = reportsExportPatient($this->entity, $this->covenant, '@CMD');
    reportsExportClaim($this->entity, $this->covenant, BillingClaimStatus::Submitted, 50, ['patient_id' => $patient->id]);

    $covenants = reportsExportCsvRows($this->get(route('panel.financial.reports.covenants.export'))->assertOk()->getContent());

    // Paciente agora sai como código + iniciais (LGPD): o texto livre do nome
    // não chega mais à célula, então não há fórmula a neutralizar ali.
    expect($covenants[1][2])->toBe("'-CMD")
        ->and($covenants[1][3])->toBe("{$patient->code} · C.")
        ->and($covenants[1][5])->toBe('50,00');
});

it('export de convênios traz as mesmas guias da tela (sem rascunho/cancelada) e "Recebido" só de guia paga', function () {
    reportsExportClaim($this->entity, $this->covenant, BillingClaimStatus::Submitted, 100);
    reportsExportClaim($this->entity, $this->covenant, BillingClaimStatus::Paid, 200, ['paid_amount' => 180, 'glosa_amount' => 20]);
    reportsExportClaim($this->entity, $this->covenant, BillingClaimStatus::Denied, 50, ['paid_amount' => 10, 'glosa_amount' => 50]);
    reportsExportClaim($this->entity, $this->covenant, BillingClaimStatus::Draft, 1000);
    reportsExportClaim($this->entity, $this->covenant, BillingClaimStatus::Cancelled, 500);

    $rows = reportsExportCsvRows($this->get(route('panel.financial.reports.covenants.export'))->assertOk()->getContent());
    $data = array_slice($rows, 1);

    expect($data)->toHaveCount(3)
        ->and(array_column($data, 4))->toEqualCanonicalizing(['Enviado', 'Pago', 'Glosado'])
        ->and(collect($data)->firstWhere(4, 'Glosado')[7])->toBe('0,00')
        ->and(collect($data)->firstWhere(4, 'Pago')[7])->toBe('180,00');
});

it('export de convênios mantém o nome do convênio excluído, marcado como inativo no idioma do usuário', function () {
    // Antes: a relação ignorava o convênio excluído (soft delete) e a coluna saía "Sem convênio".
    $deleted = Covenant::factory()->create(['entity_id' => $this->entity->id, 'active' => true, 'name' => 'UNIMED']);
    reportsExportClaim($this->entity, $deleted, BillingClaimStatus::Submitted, 100);
    $deleted->delete();

    $rows = reportsExportCsvRows($this->get(route('panel.financial.reports.covenants.export'))->assertOk()->getContent());

    expect($rows)->toHaveCount(2)
        ->and($rows[1][2])->toBe('UNIMED (inativo)');

    $rows = reportsExportCsvRows($this->withSession(['locale' => 'en'])
        ->get(route('panel.financial.reports.covenants.export'))->assertOk()->getContent());

    expect($rows[1][2])->toBe('UNIMED (inactive)');
});

it('exporta convênios em Excel (.xlsx) com aba traduzida, número como número e texto neutralizado', function () {
    $patient = reportsExportPatient($this->entity, $this->covenant, '@CMD');
    reportsExportClaim($this->entity, $this->covenant, BillingClaimStatus::Submitted, 75.5, ['patient_id' => $patient->id]);

    $response = $this->get(route('panel.financial.reports.covenants.export', ['format' => 'xlsx']))
        ->assertOk()
        ->assertHeader('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');

    $tmp = tempnam(sys_get_temp_dir(), 'fin_reports_xlsx_');
    file_put_contents($tmp, $response->getContent());

    $zip = new ZipArchive();
    expect($zip->open($tmp))->toBeTrue();
    $sheet    = (string) $zip->getFromName('xl/worksheets/sheet1.xml');
    $workbook = (string) $zip->getFromName('xl/workbook.xml');
    $zip->close();
    @unlink($tmp);

    expect($workbook)->toContain('name="Faturamento por convênio"')
        // Apóstrofo de neutralização escapado no XML (&apos;).
        ->and($sheet)->toContain('<t>&apos;-CMD</t>')
        // Paciente por código + iniciais (LGPD), nunca o nome.
        ->and($sheet)->toContain("<t>{$patient->code} · C.</t>")
        ->and($sheet)->not->toContain('@CMD')
        ->and($sheet)->toContain('<v>75.5</v>')
        ->and($sheet)->toContain('<t>Data do atendimento</t>')
        ->and($sheet)->toContain('<t>Paciente (código · iniciais)</t>');
});

it('registra um evento de auditoria por exportação, sem nome de paciente', function () {
    $patient = reportsExportPatient($this->entity, $this->covenant, 'MARIA SIGILOSA');
    reportsExportClaim($this->entity, $this->covenant, BillingClaimStatus::Submitted, 100, ['patient_id' => $patient->id]);
    reportsExportClaim($this->entity, $this->covenant, BillingClaimStatus::Paid, 80, ['patient_id' => $patient->id, 'paid_amount' => 80]);

    $from = now()->startOfMonth()->toDateString();
    $to   = now()->toDateString();

    $this->get(route('panel.financial.reports.covenants.export', ['from' => $from, 'to' => $to, 'format' => 'xlsx']))->assertOk();

    $logs = reportsExportAuditRows();

    expect($logs)->toHaveCount(1);

    $log    = $logs[0];
    $values = json_decode((string) $log->new_values, true);

    expect($log->entity_id)->toBe((string) $this->entity->id)
        ->and($log->user_id)->toBe((string) $this->entityUser->user_id)
        ->and($log->route_name)->toBe('panel.financial.reports.covenants.export')
        ->and($values)->toBe(['report' => 'covenants', 'format' => 'xlsx', 'from' => $from, 'to' => $to, 'rows' => 2])
        ->and((string) $log->new_values)->not->toContain('MARIA');

    $this->get(route('panel.financial.reports.cash-flow.export'))->assertOk();

    expect(reportsExportAuditRows())->toHaveCount(2);
});

it('falha ao gerar o PDF volta ao relatório com aviso traduzido e não registra exportação', function () {
    SnappyPdf::shouldReceive('loadView')->andThrow(new RuntimeException('wkhtmltopdf ausente'));

    $this->get(route('panel.financial.reports.cash-flow.export', ['format' => 'pdf', 'from' => '2026-09-01', 'to' => '2026-09-10']))
        ->assertRedirect(route('panel.financial.reports.cash-flow', ['from' => '2026-09-01', 'to' => '2026-09-10']))
        ->assertSessionHas('error', __('financial_reports.export_pdf_failed'));

    expect(reportsExportAuditRows())->toHaveCount(0);
});

it('data inválida não quebra a exportação nem entra no nome do arquivo', function () {
    $from = now()->startOfMonth()->toDateString();
    $to   = now()->toDateString();

    $this->get(route('panel.financial.reports.cash-flow.export', ['from' => 'abc"x', 'to' => ['y']]))
        ->assertOk()
        ->assertHeader('Content-Disposition', "attachment; filename=\"fluxo_caixa_{$from}_{$to}.csv\"");

    $this->get(route('panel.financial.reports.covenants.export', ['from' => '2026-02-30', 'format' => 'pdf']))
        ->assertOk()
        ->assertHeader('Content-Type', 'text/csv; charset=UTF-8')
        ->assertHeader('Content-Disposition', "attachment; filename=\"faturamento_convenios_{$from}_{$to}.csv\"");
});
