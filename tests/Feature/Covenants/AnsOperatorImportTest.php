<?php

use App\Enums\{CovenantSource, ImportStatus};
use App\Models\{Covenant, CovenantImport, Entity};
use App\Services\Covenants\AnsOperatorImportService;
use Illuminate\Support\Facades\{DB, Http, Storage};
use Illuminate\Support\Sleep;

/**
 * Sincronização do catálogo global de convênios com o Cadastro de Operadoras
 * da ANS (AnsOperatorImportService). CSVs gerados no teste com o MESMO
 * layout dos dados abertos (Relatorio_cadop.csv / _canceladas.csv): `;`,
 * texto entre aspas, vazio sem aspas, número sem aspas.
 */
const ANS_ACTIVE_HEADER = [
    'REGISTRO_OPERADORA', 'CNPJ', 'RAZAO_SOCIAL', 'NOME_FANTASIA', 'MODALIDADE', 'LOGRADOURO', 'NUMERO', 'COMPLEMENTO',
    'BAIRRO', 'CIDADE', 'UF', 'CEP', 'DDD', 'TELEFONE', 'FAX', 'ENDERECO_ELETRONICO', 'REPRESENTANTE',
    'CARGO_REPRESENTANTE', 'REGIAO_DE_COMERCIALIZACAO', 'DATA_REGISTRO_ANS',
];

const ANS_CANCELLED_HEADER = [...ANS_ACTIVE_HEADER, 'DATA_DESCREDENCIAMENTO', 'MOTIVO_DO_DESCREDENCIAMENTO'];

/** @return array<string, string> */
function ansRow(array $overrides = []): array
{
    return array_merge([
        'REGISTRO_OPERADORA'        => '123456',
        'CNPJ'                      => '11222333000181',
        'RAZAO_SOCIAL'              => 'OPERADORA VISÃO SAÚDE LTDA',
        'NOME_FANTASIA'             => 'VISÃO SAÚDE',
        'MODALIDADE'                => 'Medicina de Grupo',
        'LOGRADOURO'                => 'RUA DAS FLORES',
        'NUMERO'                    => '10',
        'COMPLEMENTO'               => '',
        'BAIRRO'                    => 'CENTRO',
        'CIDADE'                    => 'São Paulo',
        'UF'                        => 'SP',
        'CEP'                       => '01000000',
        'DDD'                       => '11',
        'TELEFONE'                  => '33334444',
        'FAX'                       => '',
        'ENDERECO_ELETRONICO'       => 'diretoria@visaosaude.com.br',
        'REPRESENTANTE'             => 'FULANO DE TAL DA SILVA',
        'CARGO_REPRESENTANTE'       => 'DIRETOR PRESIDENTE',
        'REGIAO_DE_COMERCIALIZACAO' => '4',
        'DATA_REGISTRO_ANS'         => '2001-05-10',
    ], $overrides);
}

/** @return array<string, string> */
function ansCancelledRow(array $overrides = []): array
{
    return array_merge(ansRow(), [
        'DATA_DESCREDENCIAMENTO'      => '2026-08-01',
        'MOTIVO_DO_DESCREDENCIAMENTO' => 'Pedido de cancelamento',
    ], $overrides);
}

/** Mesmo formato dos dados abertos da ANS. */
function ansCsv(array $header, array $rows): string
{
    $line = fn (array $values) => implode(';', array_map(
        fn ($v) => $v === '' ? '' : (ctype_digit((string) $v) && strlen((string) $v) <= 2 ? $v : '"' . str_replace('"', '""', (string) $v) . '"'),
        $values,
    ));

    return implode("\n", [
        implode(';', $header),
        ...array_map(fn (array $row) => $line(array_map(fn ($column) => $row[$column] ?? '', $header)), $rows),
    ]) . "\n";
}

/** Import de arquivos enviados (sem download), com os CSVs no disco padrão. */
function ansUploadImport(array $active, ?array $cancelled = null, ?array $modalities = null): CovenantImport
{
    Storage::disk()->put('imports/covenants/t/ativas.csv', ansCsv(ANS_ACTIVE_HEADER, $active));

    if ($cancelled !== null) {
        Storage::disk()->put('imports/covenants/t/canceladas.csv', ansCsv(ANS_CANCELLED_HEADER, $cancelled));
    }

    return CovenantImport::query()->create([
        'source'               => CovenantImport::SOURCE_UPLOAD,
        'status'               => ImportStatus::Pending,
        'modalities'           => $modalities ?? config('covenants.ans.default_modalities'),
        'active_file_path'     => 'imports/covenants/t/ativas.csv',
        'active_original_name' => 'Relatorio_cadop.csv',
        'cancelled_file_path'  => $cancelled !== null ? 'imports/covenants/t/canceladas.csv' : null,
    ]);
}

function runAnsImport(CovenantImport $import): CovenantImport
{
    app(AnsOperatorImportService::class)->process($import);

    return $import->fresh();
}

function globalCovenant(string $registry): ?Covenant
{
    return Covenant::withoutGlobalScopes()->whereNull('entity_id')->where('ans_registry', $registry)->first();
}

beforeEach(function () {
    Storage::fake();
});

it('inclui operadora nova como convênio GLOBAL com os dados oficiais (sem dado pessoal)', function () {
    $import = runAnsImport(ansUploadImport([ansRow()]));

    expect($import->status)->toBe(ImportStatus::Done)
        ->and($import->created_count)->toBe(1)
        ->and($import->processed_rows)->toBe(1);

    $c = globalCovenant('123456');
    expect($c)->not->toBeNull()
        ->and($c->entity_id)->toBeNull()
        ->and($c->name)->toBe('VISÃO SAÚDE')
        ->and($c->company_name)->toBe('Operadora Visão Saúde Ltda')
        ->and($c->trade_name)->toBe('Visão Saúde')
        ->and($c->national_registry)->toBe('11222333000181')
        ->and($c->ans_modality)->toBe('Medicina de Grupo')
        ->and($c->city)->toBe('São Paulo')
        ->and($c->uf)->toBe('SP')
        ->and($c->ans_registered_at->toDateString())->toBe('2001-05-10')
        ->and($c->ans_status)->toBe('active')
        ->and($c->source)->toBe(CovenantSource::Ans)
        ->and($c->source_synced_at)->not->toBeNull()
        ->and($c->active)->toBeTrue()
        ->and($c->code)->toStartWith('CVP')
        ->and($c->color)->toMatch('/^#[0-9A-F]{6}$/');

    // LGPD (minimização): representante, e-mail e telefone não são gravados.
    $row = json_encode(DB::table('covenants')->where('id', $c->id)->first(), JSON_UNESCAPED_UNICODE);
    expect($row)->not->toContain('FULANO')
        ->and($row)->not->toContain('diretoria@')
        ->and($row)->not->toContain('33334444');
});

it('sem nome fantasia ("******" ou vazio) usa a razão social como nome exibido', function () {
    runAnsImport(ansUploadImport([
        ansRow(['REGISTRO_OPERADORA' => '111111', 'NOME_FANTASIA' => '******', 'RAZAO_SOCIAL' => 'CAIXA DE ASSISTÊNCIA X']),
        ansRow(['REGISTRO_OPERADORA' => '222222', 'NOME_FANTASIA' => '', 'RAZAO_SOCIAL' => 'UNIMED TESTE COOPERATIVA']),
    ]));

    expect(globalCovenant('111111')->name)->toBe('CAIXA DE ASSISTÊNCIA X')
        ->and(globalCovenant('111111')->trade_name)->toBeNull()
        ->and(globalCovenant('222222')->name)->toBe('UNIMED TESTE COOPERATIVA');
});

it('CNPJ alfanumérico da ANS é mantido; CNPJ incompleto vira nulo', function () {
    runAnsImport(ansUploadImport([
        ansRow(['REGISTRO_OPERADORA' => '400001', 'NOME_FANTASIA' => 'NOVA', 'CNPJ' => '12ABC34501DE35']),
        ansRow(['REGISTRO_OPERADORA' => '400002', 'NOME_FANTASIA' => 'TRUNCADA', 'CNPJ' => '1122233300018']),
    ]));

    expect(globalCovenant('400001')->national_registry)->toBe('12ABC34501DE35')
        ->and(globalCovenant('400002')->national_registry)->toBeNull();
});

it('operadora nova fora das modalidades escolhidas não entra (odontológica por padrão)', function () {
    $import = runAnsImport(ansUploadImport([
        ansRow(['REGISTRO_OPERADORA' => '300001', 'MODALIDADE' => 'Odontologia de Grupo', 'NOME_FANTASIA' => 'DENTAL X']),
        ansRow(['REGISTRO_OPERADORA' => '300002', 'MODALIDADE' => 'Cooperativa Médica', 'NOME_FANTASIA' => 'COOP Y']),
    ]));

    expect($import->created_count)->toBe(1)
        ->and($import->skipped_modality)->toBe(1)
        ->and(globalCovenant('300001'))->toBeNull()
        ->and(globalCovenant('300002'))->not->toBeNull();
});

it('atualiza dados oficiais da operadora existente sem trocar o nome exibido; 2ª rodada não muda nada', function () {
    $existing = Covenant::factory()->create([
        'name'   => 'MEU NOME CURTO', 'ans_registry' => '123456', 'company_name' => 'Nome Antigo Ltda',
        'source' => CovenantSource::Ans, 'active' => true,
    ]);

    $first = runAnsImport(ansUploadImport([ansRow(['MODALIDADE' => 'Odontologia de Grupo'])]));

    $existing->refresh();
    expect($first->updated_count)->toBe(1)
        ->and($first->created_count)->toBe(0)
        // Modalidade só filtra NOVAS: a que já está no catálogo sempre atualiza.
        ->and($first->skipped_modality)->toBe(0)
        ->and($existing->name)->toBe('MEU NOME CURTO')
        ->and($existing->company_name)->toBe('Operadora Visão Saúde Ltda')
        ->and($existing->ans_modality)->toBe('Odontologia de Grupo')
        ->and($existing->source_synced_at)->not->toBeNull();

    $second = runAnsImport(ansUploadImport([ansRow(['MODALIDADE' => 'Odontologia de Grupo'])]));
    expect($second->updated_count)->toBe(0)
        ->and($second->unchanged_count)->toBe(1)
        ->and(Covenant::withoutGlobalScopes()->where('ans_registry', '123456')->count())->toBe(1);
});

it('registro ANS gravado sem os zeros à esquerda casa com a operadora (sem duplicar)', function () {
    Covenant::factory()->create(['name' => 'ZERO A ESQUERDA', 'ans_registry' => '5711', 'source' => CovenantSource::Ans]);

    $import = runAnsImport(ansUploadImport([ansRow(['REGISTRO_OPERADORA' => '005711'])]));

    expect($import->created_count)->toBe(0)
        ->and($import->updated_count)->toBe(1)
        ->and(Covenant::withoutGlobalScopes()->whereNull('entity_id')->where('name', 'like', '%VISÃO%')->exists())->toBeFalse()
        ->and(globalCovenant('005711')?->name)->toBe('ZERO A ESQUERDA');
});

it('[TENANT] convênio próprio de clínica com o mesmo registro nunca é alterado', function () {
    $clinic = Entity::factory()->create(['is_client' => true]);
    $own    = Covenant::factory()->create([
        'entity_id' => $clinic->id, 'name' => 'DA CLINICA', 'ans_registry' => '123456', 'company_name' => 'Da Clínica',
    ]);

    runAnsImport(ansUploadImport([ansRow()]));

    $own->refresh();
    expect($own->company_name)->toBe('Da Clínica')
        ->and($own->source)->toBe(CovenantSource::Manual)
        ->and($own->source_synced_at)->toBeNull()
        ->and(globalCovenant('123456'))->not->toBeNull();
});

it('nome exibido repetido ganha o registro ANS junto (sem quebrar quem casa pelo nome)', function () {
    Covenant::factory()->create(['name' => 'VISÃO SAÚDE']); // manual, sem registro

    runAnsImport(ansUploadImport([ansRow()]));

    expect(globalCovenant('123456')->name)->toBe('VISÃO SAÚDE (123456)');
});

it('cancelada na ANS: marca, desativa na 1ª detecção e respeita reativação manual depois', function () {
    $c      = Covenant::factory()->create(['name' => 'CANCELADA', 'ans_registry' => '777777', 'source' => CovenantSource::Ans, 'active' => true]);
    $active = [ansRow(['REGISTRO_OPERADORA' => '999999', 'NOME_FANTASIA' => 'OUTRA ATIVA'])];

    $first = runAnsImport(ansUploadImport($active, [ansCancelledRow(['REGISTRO_OPERADORA' => '777777'])]));

    $c->refresh();
    expect($first->deactivated_count)->toBe(1)
        ->and($c->active)->toBeFalse()
        ->and($c->ans_status)->toBe('cancelled')
        ->and($c->ans_cancelled_at->toDateString())->toBe('2026-08-01')
        ->and($c->ans_cancellation_reason)->toBe('Pedido de cancelamento')
        ->and($c->trashed())->toBeFalse(); // nunca exclui

    // Admin reativou (clínicas ainda atendem pelo convênio em transição).
    $c->update(['active' => true]);

    $second = runAnsImport(ansUploadImport($active, [ansCancelledRow(['REGISTRO_OPERADORA' => '777777'])]));
    expect($second->deactivated_count)->toBe(0)
        ->and($c->fresh()->active)->toBeTrue();
});

it('cancelada que não está no catálogo é ignorada (não cria convênio)', function () {
    $import = runAnsImport(ansUploadImport([ansRow()], [ansCancelledRow(['REGISTRO_OPERADORA' => '888888'])]));

    expect(globalCovenant('888888'))->toBeNull()
        ->and($import->status)->toBe(ImportStatus::Done)
        ->and($import->total_rows)->toBe(2);
});

it('operadora nas duas listas vale a de ativas (recadastrada)', function () {
    $c = Covenant::factory()->create(['name' => 'RECADASTRADA', 'ans_registry' => '123456', 'source' => CovenantSource::Ans, 'active' => true]);

    $import = runAnsImport(ansUploadImport([ansRow()], [ansCancelledRow()]));

    expect($import->deactivated_count)->toBe(0)
        ->and($c->fresh()->active)->toBeTrue()
        ->and($c->fresh()->ans_status)->toBe('active');
});

it('arquivo com outro cabeçalho falha com mensagem clara e não altera nada', function () {
    Storage::disk()->put('imports/covenants/t/ativas.csv', "NOME;VALOR\nX;1\n");
    $c      = Covenant::factory()->create(['name' => 'INTACTO', 'ans_registry' => '123456', 'source' => CovenantSource::Ans]);
    $import = CovenantImport::query()->create([
        'source'     => CovenantImport::SOURCE_UPLOAD, 'status' => ImportStatus::Pending,
        'modalities' => ['Medicina de Grupo'], 'active_file_path' => 'imports/covenants/t/ativas.csv',
    ]);

    $import = runAnsImport($import);

    expect($import->status)->toBe(ImportStatus::Failed)
        ->and($import->error)->toBe(__('manager_covenants.import_header_not_found'))
        ->and($c->fresh()->source_synced_at)->toBeNull();
});

it('lista de ativas sem nenhuma operadora válida falha e NÃO processa cancelamentos', function () {
    $c = Covenant::factory()->create(['name' => 'PROTEGIDA', 'ans_registry' => '777777', 'source' => CovenantSource::Ans, 'active' => true]);

    $import = runAnsImport(ansUploadImport(
        [ansRow(['REGISTRO_OPERADORA' => 'abc']), ansRow(['REGISTRO_OPERADORA' => '1234567'])],
        [ansCancelledRow(['REGISTRO_OPERADORA' => '777777'])],
    ));

    expect($import->status)->toBe(ImportStatus::Failed)
        ->and($import->error)->toBe(__('manager_covenants.import_no_valid_rows'))
        ->and($import->skipped_invalid)->toBe(2)
        ->and($c->fresh()->active)->toBeTrue();
});

it('arquivo em Latin-1 (salvo pelo Excel) é convertido', function () {
    Storage::disk()->put('imports/covenants/t/ativas.csv', mb_convert_encoding(ansCsv(ANS_ACTIVE_HEADER, [ansRow()]), 'ISO-8859-1', 'UTF-8'));
    $import = CovenantImport::query()->create([
        'source'     => CovenantImport::SOURCE_UPLOAD, 'status' => ImportStatus::Pending,
        'modalities' => ['Medicina de Grupo'], 'active_file_path' => 'imports/covenants/t/ativas.csv',
    ]);

    expect(runAnsImport($import)->status)->toBe(ImportStatus::Done)
        ->and(globalCovenant('123456')->name)->toBe('VISÃO SAÚDE');
});

it('baixa as duas listas da ANS, guarda os arquivos e sincroniza', function () {
    Http::preventStrayRequests();
    Http::fake([
        config('covenants.ans.active_url')    => Http::response(ansCsv(ANS_ACTIVE_HEADER, [ansRow()])),
        config('covenants.ans.cancelled_url') => Http::response(ansCsv(ANS_CANCELLED_HEADER, [ansCancelledRow(['REGISTRO_OPERADORA' => '888888'])])),
    ]);

    $import = CovenantImport::query()->create([
        'source' => CovenantImport::SOURCE_ANS, 'status' => ImportStatus::Pending, 'modalities' => ['Medicina de Grupo'],
    ]);

    $import = runAnsImport($import);

    expect($import->status)->toBe(ImportStatus::Done)
        ->and($import->created_count)->toBe(1)
        ->and($import->active_original_name)->toBe('Relatorio_cadop.csv')
        ->and($import->cancelled_original_name)->toBe('Relatorio_cadop_canceladas.csv');
    Storage::disk()->assertExists($import->active_file_path);
    Storage::disk()->assertExists($import->cancelled_file_path);
});

it('ANS fora do ar: falha com mensagem sugerindo o envio manual, sem alterar o catálogo', function () {
    Sleep::fake();
    Http::preventStrayRequests();
    Http::fake(['*' => Http::response('erro', 503)]);
    $c = Covenant::factory()->create(['name' => 'INTACTO', 'ans_registry' => '123456', 'source' => CovenantSource::Ans]);

    $import = runAnsImport(CovenantImport::query()->create([
        'source' => CovenantImport::SOURCE_SCHEDULED, 'status' => ImportStatus::Pending, 'modalities' => ['Medicina de Grupo'],
    ]));

    expect($import->status)->toBe(ImportStatus::Failed)
        ->and($import->error)->toBe(__('manager_covenants.import_download_failed'))
        ->and($c->fresh()->source_synced_at)->toBeNull();
});

it('resposta da ANS acima do limite é recusada', function () {
    config(['covenants.ans.max_bytes' => 100]);
    Http::preventStrayRequests();
    Http::fake(['*' => Http::response(ansCsv(ANS_ACTIVE_HEADER, [ansRow(), ansRow()]))]);

    $import = runAnsImport(CovenantImport::query()->create([
        'source' => CovenantImport::SOURCE_ANS, 'status' => ImportStatus::Pending, 'modalities' => ['Medicina de Grupo'],
    ]));

    expect($import->status)->toBe(ImportStatus::Failed)
        ->and($import->error)->toBe(__('manager_covenants.import_download_failed'));
});

it('arquivo enviado sumiu do armazenamento: falha com mensagem própria', function () {
    $import = runAnsImport(CovenantImport::query()->create([
        'source'     => CovenantImport::SOURCE_UPLOAD, 'status' => ImportStatus::Pending,
        'modalities' => ['Medicina de Grupo'], 'active_file_path' => 'imports/covenants/nao-existe.csv',
    ]));

    expect($import->status)->toBe(ImportStatus::Failed)
        ->and($import->error)->toBe(__('manager_covenants.import_file_missing'));
});
