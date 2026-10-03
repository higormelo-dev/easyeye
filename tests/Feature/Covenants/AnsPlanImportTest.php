<?php

use App\Enums\{CovenantSource, ImportStatus};
use App\Models\{Covenant, CovenantImport, CovenantPlan, Entity, Patient};
use App\Services\Covenants\AnsOperatorImportService;
use Illuminate\Support\Facades\{DB, Http, Storage};
use Illuminate\Support\Sleep;

/**
 * Etapa de PLANOS da sincronização com a ANS (AnsPlanImportService), rodando
 * dentro da sincronização completa (operadoras → planos). CSV no MESMO
 * layout de "Características dos Produtos da Saúde Suplementar".
 */
const PLAN_HEADER = [
    'ID_PLANO', 'CD_PLANO', 'NM_PLANO', 'REGISTRO_OPERADORA', 'RAZAO_SOCIAL', 'GR_MODALIDADE', 'PORTE_OPERADORA',
    'VIGENCIA_PLANO', 'CONTRATACAO', 'GR_CONTRATACAO', 'SGMT_ASSISTENCIAL', 'GR_SGMT_ASSISTENCIAL', 'LG_ODONTOLOGICO',
    'OBSTETRICIA', 'COBERTURA', 'TIPO_FINANCIAMENTO', 'ABRANGENCIA_COBERTURA', 'ID_GEO_COBERTURA', 'FATOR_MODERADOR',
    'ACOMODACAO_HOSPITALAR', 'LIVRE_ESCOLHA', 'SITUACAO_PLANO', 'DT_SITUACAO', 'DT_REGISTRO_PLANO', 'DT_ATUALIZACAO',
];

/** @return array<string, string> */
function planRow(array $overrides = []): array
{
    return array_merge([
        'ID_PLANO'              => '1000001',
        'CD_PLANO'              => '471234567',
        'NM_PLANO'              => 'Visão Saúde Plus Enfermaria',
        'REGISTRO_OPERADORA'    => '123456',
        'RAZAO_SOCIAL'          => 'OPERADORA VISÃO SAÚDE LTDA',
        'GR_MODALIDADE'         => 'Medicina De Grupo',
        'PORTE_OPERADORA'       => 'Médio',
        'VIGENCIA_PLANO'        => 'P',
        'CONTRATACAO'           => 'Coletivo empresarial',
        'GR_CONTRATACAO'        => 'Coletivo empresarial',
        'SGMT_ASSISTENCIAL'     => 'Ambulatorial + Hospitalar com obstetrícia',
        'GR_SGMT_ASSISTENCIAL'  => 'Ambulatorial + Hospitalar',
        'LG_ODONTOLOGICO'       => '0',
        'OBSTETRICIA'           => 'Com Obstetrícia',
        'COBERTURA'             => 'Médico-hospitalar',
        'TIPO_FINANCIAMENTO'    => 'Preestabelecido',
        'ABRANGENCIA_COBERTURA' => 'Grupo de municípios',
        'ID_GEO_COBERTURA'      => 'ABC123',
        'FATOR_MODERADOR'       => 'Coparticipação',
        'ACOMODACAO_HOSPITALAR' => 'Coletiva',
        'LIVRE_ESCOLHA'         => 'Ausente',
        'SITUACAO_PLANO'        => 'Ativo',
        'DT_SITUACAO'           => '2015-03-01',
        'DT_REGISTRO_PLANO'     => '2015-02-10',
        'DT_ATUALIZACAO'        => '2026-10-03',
    ], $overrides);
}

function planCsv(array $rows, array $header = PLAN_HEADER): string
{
    $quote = fn ($v) => $v === '' ? '' : (ctype_digit((string) $v) && strlen((string) $v) <= 2 ? $v : '"' . str_replace('"', '""', (string) $v) . '"');

    return implode("\n", [
        implode(';', $header),
        ...array_map(fn (array $row) => implode(';', array_map(fn ($c) => $quote($row[$c] ?? ''), $header)), $rows),
    ]) . "\n";
}

/** Lista de operadoras ativas mínima (o catálogo já tem a operadora). */
function planOperatorsCsv(): string
{
    return "REGISTRO_OPERADORA;CNPJ;RAZAO_SOCIAL;NOME_FANTASIA;MODALIDADE;CIDADE;UF;DATA_REGISTRO_ANS\n"
        . "\"123456\";\"11222333000181\";\"OPERADORA VISÃO SAÚDE LTDA\";\"VISÃO SAÚDE\";\"Medicina de Grupo\";\"São Paulo\";\"SP\";\"2001-05-10\"\n";
}

/**
 * Registra os fakes uma vez; a resposta dos planos é lida a cada chamada
 * (cada teste troca o arquivo entre uma sincronização e outra).
 */
function fakeAnsDownloads(string|Closure $plans): void
{
    test()->plansResponse = $plans;

    if (test()->ansFaked) {
        return;
    }

    test()->ansFaked = true;

    Http::preventStrayRequests();
    Http::fake([
        config('covenants.ans.active_url')    => fn () => Http::response(planOperatorsCsv()),
        config('covenants.ans.cancelled_url') => fn () => Http::response("REGISTRO_OPERADORA;DATA_DESCREDENCIAMENTO;MOTIVO_DO_DESCREDENCIAMENTO\n"),
        config('covenants.ans.plans_url')     => fn () => is_string(test()->plansResponse)
            ? Http::response(test()->plansResponse)
            : (test()->plansResponse)(),
    ]);
}

function runFullSync(string $source = CovenantImport::SOURCE_ANS): CovenantImport
{
    $import = CovenantImport::query()->create([
        'source' => $source, 'status' => ImportStatus::Pending, 'modalities' => ['Medicina de Grupo'],
    ]);

    app(AnsOperatorImportService::class)->process($import);

    return $import->fresh();
}

function ansPlan(string $planId): ?CovenantPlan
{
    return CovenantPlan::withoutGlobalScopes()->where('ans_plan_id', $planId)->first();
}

beforeEach(function () {
    Storage::fake();
    Sleep::fake();
    $this->ansFaked      = false;
    $this->plansResponse = '';
    $this->operator      = Covenant::factory()->create([
        'name' => 'VISÃO SAÚDE', 'ans_registry' => '123456', 'source' => CovenantSource::Ans, 'active' => true,
    ]);
});

it('inclui planos médicos ativos/suspensos das operadoras do catálogo, com os dados do produto', function () {
    fakeAnsDownloads(planCsv([
        planRow(),
        planRow(['ID_PLANO' => '1000002', 'NM_PLANO' => 'Antigo Suspenso', 'SITUACAO_PLANO' => 'Suspenso', 'VIGENCIA_PLANO' => 'A', 'CD_PLANO' => '20401']),
        planRow(['ID_PLANO' => '1000003', 'NM_PLANO' => 'Só Dente', 'COBERTURA' => 'Odontológica']),
        planRow(['ID_PLANO' => '1000004', 'NM_PLANO' => 'Já Cancelado', 'SITUACAO_PLANO' => 'Cancelado']),
        planRow(['ID_PLANO' => '1000005', 'NM_PLANO' => 'De Fora', 'REGISTRO_OPERADORA' => '999999']),
        planRow(['ID_PLANO' => 'abc']),
    ]));

    $import = runFullSync();

    expect($import->status)->toBe(ImportStatus::Done)
        ->and($import->plans_error)->toBeNull()
        ->and($import->plans_created_count)->toBe(2)
        ->and($import->plans_skipped_count)->toBe(4)
        // Barra de progresso: operadoras (1) + planos (6).
        ->and($import->processed_rows)->toBe(7);

    $plan = ansPlan('1000001');
    expect($plan->entity_id)->toBeNull()
        ->and($plan->covenant_id)->toBe($this->operator->id)
        ->and($plan->name)->toBe('VISÃO SAÚDE PLUS ENFERMARIA')
        ->and($plan->ans_code)->toBe('471234567')
        ->and($plan->contracting)->toBe('Coletivo empresarial')
        ->and($plan->segmentation)->toBe('Ambulatorial + Hospitalar com obstetrícia')
        ->and($plan->coverage_area)->toBe('Grupo de municípios')
        ->and($plan->moderating_factor)->toBe('Coparticipação')
        ->and($plan->regulation)->toBe('P')
        ->and($plan->ans_status)->toBe('active')
        ->and($plan->ans_registered_at->toDateString())->toBe('2015-02-10')
        ->and($plan->source)->toBe(CovenantSource::Ans)
        ->and($plan->active)->toBeTrue();

    expect(ansPlan('1000002')->ans_status)->toBe('suspended')
        ->and(ansPlan('1000002')->active)->toBeTrue()
        ->and(ansPlan('1000003'))->toBeNull()
        ->and(ansPlan('1000004'))->toBeNull()
        ->and(ansPlan('1000005'))->toBeNull();
});

it('2ª rodada não regrava nada; plano cancelado na ANS deixa de ser escolhível sem perder o paciente', function () {
    fakeAnsDownloads(planCsv([planRow()]));
    runFullSync();

    $clinic  = Entity::factory()->create(['is_client' => true]);
    $patient = Patient::factory()->create([
        'entity_id' => $clinic->id, 'covenant_id' => $this->operator->id, 'covenant_plan_id' => ansPlan('1000001')->id,
    ]);

    fakeAnsDownloads(planCsv([planRow()]));
    $second = runFullSync();
    expect($second->plans_unchanged_count)->toBe(1)
        ->and($second->plans_updated_count)->toBe(0);

    fakeAnsDownloads(planCsv([planRow(['SITUACAO_PLANO' => 'Cancelado', 'DT_SITUACAO' => '2026-09-01'])]));
    $third = runFullSync();

    $plan = ansPlan('1000001');
    expect($third->plans_updated_count)->toBe(1)
        ->and($third->plans_deactivated_count)->toBe(1)
        ->and($plan->active)->toBeFalse()
        ->and($plan->ans_status)->toBe('cancelled')
        ->and($plan->trashed())->toBeFalse()
        ->and(CovenantPlan::withoutGlobalScopes()->where('ans_plan_id', 1000001)->count())->toBe(1)
        ->and(Patient::withoutGlobalScopes()->find($patient->id)->covenant_plan_id)->toBe($plan->id);
});

it('mudança de nome na ANS atualiza o mesmo plano (chave ID_PLANO, sem duplicar)', function () {
    fakeAnsDownloads(planCsv([planRow()]));
    runFullSync();
    $id = ansPlan('1000001')->id;

    fakeAnsDownloads(planCsv([planRow(['NM_PLANO' => 'Visão Saúde Plus Apartamento', 'ACOMODACAO_HOSPITALAR' => 'Individual'])]));
    runFullSync();

    $plan = ansPlan('1000001');
    expect($plan->id)->toBe($id)
        ->and($plan->name)->toBe('VISÃO SAÚDE PLUS APARTAMENTO')
        ->and($plan->accommodation)->toBe('Individual');
});

it('[TENANT] plano próprio de clínica e plano manual do manager nunca são alterados', function () {
    $clinic = Entity::factory()->create(['is_client' => true]);
    $own    = CovenantPlan::withoutGlobalScopes()->forceCreate([
        'entity_id' => $clinic->id, 'covenant_id' => $this->operator->id, 'name' => 'VISÃO SAÚDE PLUS ENFERMARIA',
        'source'    => CovenantSource::Manual->value, 'active' => true,
    ]);
    $manual = CovenantPlan::withoutGlobalScopes()->forceCreate([
        'entity_id' => null, 'covenant_id' => $this->operator->id, 'name' => 'PLANO DO MANAGER',
        'source'    => CovenantSource::Manual->value, 'active' => true,
    ]);

    fakeAnsDownloads(planCsv([planRow()]));
    runFullSync();

    expect($own->fresh()->ans_plan_id)->toBeNull()
        ->and($own->fresh()->entity_id)->toBe($clinic->id)
        ->and($manual->fresh()->ans_plan_id)->toBeNull()
        ->and(CovenantPlan::withoutGlobalScopes()->count())->toBe(3);
});

it('falha só nos planos: operadoras ficam atualizadas e o import termina com aviso', function () {
    fakeAnsDownloads(fn () => Http::response('indisponível', 503));

    $import = runFullSync();

    expect($import->status)->toBe(ImportStatus::Done)
        ->and($import->error)->toBeNull()
        ->and($import->plans_error)->toBe(__('manager_covenants.plans_download_failed'))
        ->and($import->updated_count)->toBe(1)
        ->and($this->operator->fresh()->company_name)->toBe('Operadora Visão Saúde Ltda')
        ->and(CovenantPlan::withoutGlobalScopes()->count())->toBe(0);
});

it('arquivo de planos com outro cabeçalho: aviso claro, nada gravado', function () {
    fakeAnsDownloads("NOME;VALOR\nX;1\n");

    $import = runFullSync();

    expect($import->status)->toBe(ImportStatus::Done)
        ->and($import->plans_error)->toBe(__('manager_covenants.plans_header_not_found'))
        ->and(CovenantPlan::withoutGlobalScopes()->count())->toBe(0);
});

it('arquivo de planos acima do teto é recusado', function () {
    config(['covenants.ans.plans_max_bytes' => 100]);
    fakeAnsDownloads(planCsv([planRow(), planRow(['ID_PLANO' => '1000002'])]));

    expect(runFullSync()->plans_error)->toBe(__('manager_covenants.plans_download_failed'));
});

it('envio manual de CSV não baixa os planos; chave desligada também não', function () {
    Http::preventStrayRequests();
    Http::fake();
    Storage::disk()->put('imports/covenants/t/ativas.csv', planOperatorsCsv());

    $import = CovenantImport::query()->create([
        'source'           => CovenantImport::SOURCE_UPLOAD, 'status' => ImportStatus::Pending, 'modalities' => ['Medicina de Grupo'],
        'active_file_path' => 'imports/covenants/t/ativas.csv',
    ]);
    app(AnsOperatorImportService::class)->process($import);

    expect($import->fresh()->status)->toBe(ImportStatus::Done)
        ->and($import->fresh()->plans_error)->toBeNull();
    Http::assertNothingSent();

    config(['covenants.ans.plans_enabled' => false]);
    fakeAnsDownloads(planCsv([planRow()]));
    runFullSync();

    Http::assertNotSent(fn ($request) => $request->url() === config('covenants.ans.plans_url'));
    expect(CovenantPlan::withoutGlobalScopes()->count())->toBe(0);
});

it('lotes grandes: grava em blocos sem estourar e conta certo', function () {
    $rows = [];

    for ($i = 1; $i <= 2500; $i++) {
        $rows[] = planRow(['ID_PLANO' => (string) (2000000 + $i), 'CD_PLANO' => (string) (480000000 + $i), 'NM_PLANO' => "Plano {$i}"]);
    }
    fakeAnsDownloads(planCsv($rows));

    $import = runFullSync();

    expect($import->plans_created_count)->toBe(2500)
        ->and(DB::table('covenant_plans')->count())->toBe(2500);
});
